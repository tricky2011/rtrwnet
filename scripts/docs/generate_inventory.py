#!/usr/bin/env python3
"""Build source-only documentation inventories; never load app config or .env."""
import json
import re
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
DOCS = ROOT / "docs"


def source_link(path, line=1):
    return f"[{path}:{line}](../{path}#L{line})"


def cell(value):
    return str(value).replace("|", "\\|").replace("\n", " ")


def main():
    folders = ("controllers", "models", "libraries", "helpers", "jobs", "migrations", "views")
    counts = {name: len(list((ROOT / "application" / name).rglob("*.php"))) for name in folders}
    controllers = []
    for path in sorted((ROOT / "application/controllers").rglob("*.php")):
        content = path.read_text()
        cls = re.search(r"class\s+(\w+)\s+extends\s+(\w+)", content)
        if not cls:
            continue
        methods = []
        for match in re.finditer(r"public\s+function\s+(\w+)\s*\(([^)]*)\)", content):
            if match[1] == "__construct":
                continue
            methods.append({"name": match[1], "arguments": match[2], "line": content[:match.start()].count("\n") + 1})
        controllers.append({"path": str(path.relative_to(ROOT)), "class": cls[1], "extends": cls[2], "methods": methods})

    route_source = (ROOT / "application/config/routes.php").read_text()
    routes = []
    # This project declares literal route assignments; dynamic routes would need a PHP parser.
    for match in re.finditer(r"^\s*\$route\['([^']+)'\]\s*=\s*'([^']*)'\s*;", route_source, re.M):
        routes.append({"uri": match[1], "target": match[2], "line": route_source[:match.start()].count("\n") + 1})

    migrations = [str(p.relative_to(ROOT)) for p in sorted((ROOT / "application/migrations").glob("*.php"))]
    lock = json.loads((ROOT / "composer.lock").read_text())
    packages = [{"name": p["name"], "version": p["version"], "php": p.get("require", {}).get("php", "")} for p in lock["packages"]]
    env = {}
    for path in sorted((ROOT / "application").rglob("*.php")):
        if any(part in ("logs", "cache", "third_party") for part in path.parts):
            continue
        for key in re.findall(r"getenv\(\s*['\"]([A-Z][A-Z0-9_]+)['\"]\s*\)", path.read_text()):
            env.setdefault(key, set()).add(str(path.relative_to(ROOT)))
    # Include entrypoint variables and example contract without reading their live values.
    for key in re.findall(r"getenv\(\s*['\"]([A-Z][A-Z0-9_]+)['\"]\s*\)", (ROOT / "index.php").read_text()):
        env.setdefault(key, set()).add("index.php")
    for key in re.findall(r"^([A-Z][A-Z0-9_]+)=", (ROOT / ".env.example").read_text(), re.M):
        env.setdefault(key, set()).add(".env.example")
    revision = subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=ROOT, text=True).strip()
    inventory = {"basis": "Static source inventory; not runtime verification or complete API contract", "source_revision": revision, "php_file_counts": counts, "routes": routes, "controllers": controllers, "migrations": migrations, "composer_production_packages": packages, "environment_variable_references": {k: sorted(v) for k, v in sorted(env.items())}}
    (DOCS / "INVENTARIS_SOURCE_RTRWNET.json").write_text(json.dumps(inventory, ensure_ascii=False, indent=2) + "\n")

    out = ["# Referensi endpoint dan inventaris source RTRWNet", "", "Lampiran untuk [dokumentasi lengkap](13_DOKUMENTASI_LENGKAP_BILLING_RTRWNET.md). Dibangkitkan dari source oleh `python3 scripts/docs/generate_inventory.py`.", "", f"Revisi source dasar: `{revision}`. Inventaris membaca working tree saat generator berjalan. Tidak membaca `.env`, database, log runtime, atau kredensial.", "", "## 1. Batas pembacaan", "", "Daftar route menunjukkan pemetaan URI yang dideklarasikan, bukan jaminan endpoint berhasil dijalankan. HTTP method, autentikasi, role, validasi, dan efek samping ditentukan controller. Public method juga dapat berupa callback validasi/helper; keberadaannya bukan kontrak API publik. Route konvensional CI3 tetap berlaku di luar alias eksplisit.", "", "## 2. Ukuran source", "", "| Folder application | Jumlah file PHP |", "| --- | ---: |"]
    out.extend(f"| `{name}/` | {count} |" for name, count in counts.items())
    out += ["", "## 3. Route eksplisit", "", "`default_controller` dan `404_override` adalah konfigurasi khusus. `translate_uri_dashes = FALSE` di source; alias dengan tanda hubung yang ada di tabel dipetakan secara eksplisit.", "", "| URI / konfigurasi | Target | Sumber |", "| --- | --- | --- |"]
    out.extend(f"| `{cell(r['uri'])}` | `{cell(r['target'])}` | {source_link('application/config/routes.php', r['line'])} |" for r in routes)
    out += ["", "## 4. Controller dan public method", ""]
    for controller in controllers:
        out += [f"### {controller['path']}", "", f"Class `{controller['class']}` mewarisi `{controller['extends']}`.", "", "| Method | Sumber |", "| --- | --- |"]
        out.extend(f"| `{cell(m['name'] + '(' + m['arguments'] + ')')}` | {source_link(controller['path'], m['line'])} |" for m in controller["methods"])
        out.append("")
    out += ["## 5. Migrasi PHP dalam urutan nama", "", "Urutan ini bukan bukti seluruh migrasi telah terpasang. Status aktual tersimpan di tabel `migrations`. Sejumlah migrasi awal memerlukan tabel dasar yang berasal dari schema terpisah.", ""]
    out.extend(f"- {source_link(path)}" for path in migrations)
    out += ["", "## 6. Dependency produksi Composer yang dikunci", "", "| Package | Versi lock | Constraint PHP package |", "| --- | --- | --- |"]
    out.extend(f"| `{p['name']}` | `{p['version']}` | `{cell(p['php'])}` |" for p in packages)
    out += ["", "## 7. Referensi variabel environment", "", "Hanya nama dan lokasi pembacaan. Variabel yang diteruskan lewat wrapper dinamis bisa tidak tertangkap pemindai; `.env.example` ikut dijadikan sumber nama. Defaults dan arti utama dijelaskan di [spesifikasi teknis](14_SPESIFIKASI_TEKNIS_DAN_DATA.md).", "", "| Nama | Lokasi referensi |", "| --- | --- |"]
    out.extend(f"| `{key}` | " + ", ".join(source_link(path) for path in sorted(paths)) + " |" for key, paths in sorted(env.items()))
    (DOCS / "16_REFERENSI_ENDPOINT_DAN_SOURCE.md").write_text("\n".join(out) + "\n")
    print(f"Generated inventories: {len(routes)} route assignments, {len(controllers)} controllers, {len(migrations)} migrations, {len(env)} environment names.")


if __name__ == "__main__":
    main()
