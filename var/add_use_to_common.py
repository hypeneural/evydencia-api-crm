from pathlib import Path

path = Path("app/OpenApi/Schemas/CommonSchemas.php")
lines = path.read_text(encoding="utf-8").splitlines(keepends=True)
for idx, line in enumerate(lines):
    if line.strip() == "namespace App\\OpenApi\\Schemas;":
        insert_index = idx + 1
        break
else:
    raise SystemExit("namespace not found")
if insert_index < len(lines) and lines[insert_index].strip() == "use OpenApi\\Annotations as OA;":
    pass
else:
    lines.insert(insert_index, "use OpenApi\\Annotations as OA;\r\n\r\n")
path.write_text(''.join(lines), encoding="utf-8")
