from pathlib import Path

text = Path("app/OpenApi/Schemas/CommonSchemas.php").read_text(encoding="utf-8")
pos = text.index("ErrorEnvelope")
print(text[pos-60:pos+60])
