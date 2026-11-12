#!/bin/sh
set -eu

if [ "${1:-}" != '--production' ]; then
    printf '%s\n' 'Faqat production uchun: sh install-build-sync.sh --production [public_html_path]' >&2
    exit 1
fi
shift

if [ "$#" -gt 1 ]; then
    printf '%s\n' 'Xatolik: faqat bitta public_html yo‘lini kiriting.' >&2
    exit 1
fi

project_dir=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
public_dir=${1:-"$(dirname "$project_dir")/public_html"}

cd "$project_dir"

if [ ! -f public/build/manifest.json ] || [ ! -f public/js/pos.js ]; then
    printf '%s\n' 'Xatolik: public/build/manifest.json yoki public/js/pos.js topilmadi.' >&2
    exit 1
fi

if [ ! -d "$public_dir" ]; then
    printf 'Xatolik: public papka topilmadi: %s\n' "$public_dir" >&2
    exit 1
fi

public_dir=$(CDPATH= cd -- "$public_dir" && pwd)
source_public_dir=$(CDPATH= cd -- "$project_dir/public" && pwd)

if [ "$public_dir" = "$source_public_dir" ]; then
    printf '%s\n' 'Xatolik: nusxalash manzili loyihaning public papkasidan boshqa bo‘lishi kerak.' >&2
    exit 1
fi

hooks_dir=$(git rev-parse --git-path hooks)

for hook in post-merge post-rewrite; do
    if [ -e "$hooks_dir/$hook" ] && ! grep -q '^# aria-build-sync$' "$hooks_dir/$hook"; then
        printf 'Xatolik: mavjud hook almashtirilmadi: %s\n' "$hooks_dir/$hook" >&2
        exit 1
    fi
done

mkdir -p "$hooks_dir"
git config --local deploy.publicPath "$public_dir"
git config --local deploy.buildSyncEnabled true

for hook in post-merge post-rewrite; do
    cat > "$hooks_dir/$hook" <<'HOOK'
#!/bin/sh
# aria-build-sync
set -eu

if [ "$(git config --local --get deploy.buildSyncEnabled || true)" != 'true' ]; then
    exit 0
fi

project_dir=$(git rev-parse --show-toplevel)
public_dir=$(git config --local --get deploy.publicPath)

if [ ! -f "$project_dir/public/build/manifest.json" ] || [ ! -f "$project_dir/public/js/pos.js" ]; then
    printf '%s\n' 'Xatolik: build yoki pos.js nusxalanmadi, manba fayllari topilmadi.' >&2
    exit 1
fi

if [ ! -d "$public_dir" ]; then
    printf 'Xatolik: public papka topilmadi: %s\n' "$public_dir" >&2
    exit 1
fi

mkdir -p "$public_dir/build" "$public_dir/js"

for entry in "$project_dir"/public/build/*; do
    [ -e "$entry" ] || continue
    [ "$(basename "$entry")" != 'manifest.json' ] || continue
    cp -a "$entry" "$public_dir/build/"
done

cp -a "$project_dir/public/js/pos.js" "$public_dir/js/pos.js"
cp -a "$project_dir/public/build/manifest.json" "$public_dir/build/manifest.json"
printf 'Build va POS JavaScript yangilandi: %s\n' "$public_dir"
HOOK
    chmod +x "$hooks_dir/$hook"
done

sh "$hooks_dir/post-merge"
printf '%s\n' 'Git pull uchun build va pos.js nusxalash yoqildi.'
