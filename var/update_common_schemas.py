import re
from pathlib import Path

path = Path("app/OpenApi/Schemas/CommonSchemas.php")
text = path.read_text(encoding="utf-8")
pattern = re.compile(r" \* @OA\\Schema\(\r?\n \*     schema=\"ErrorEnvelope\",\r?\n(?P<body>.*?)(?= \*\r?\n \* @OA\\Schema\(\r?\n \*     schema=\"SuccessEnvelope\",)", re.DOTALL)
new_block = " * @OA\\Schema(\n *     schema=\"Error\",\n *     type=\"object\",\n *     required={\"code\",\"message\"},\n *     @OA\\Property(property=\"code\", type=\"string\", example=\"unprocessable_entity\"),\n *     @OA\\Property(property=\"message\", type=\"string\", example=\"Parametros invalidos.\"),\n *     @OA\\Property(property=\"details\", type=\"object\", nullable=true, example={\"provider_status\":502,\"provider_response\":\"Timeout while calling upstream\"}),\n *     @OA\\Property(\n *         property=\"errors\",\n *         type=\"array\",\n *         nullable=true,\n *         description=\"Lista opcional com erros de validacao por campo.\",\n *         @OA\\Items(ref=\"#/components/schemas/ErrorDetail\")\n *     )\n * )\n *\n * @OA\\Schema(\n *     schema=\"ErrorEnvelope\",\n *     type=\"object\",\n *     required={\"success\",\"error\",\"trace_id\"},\n *     @OA\\Property(property=\"success\", type=\"boolean\", example=false),\n *     @OA\\Property(property=\"error\", ref=\"#/components/schemas/Error\"),\n *     @OA\\Property(property=\"trace_id\", type=\"string\", example=\"a1b2c3d4e5f6a7b8\"),\n *     @OA\\Property(\n *         property=\"meta\",\n *         type=\"object\",\n *         nullable=true,\n *         description=\"Contexto opcional sobre a falha\",\n *         @OA\\Property(property=\"timestamp\", type=\"string\", format=\"date-time\", example=\"2025-10-03T18:20:00Z\"),\n *         @OA\\Property(property=\"correlation_id\", type=\"string\", nullable=true)\n *     )\n * )\r\n"
text_new, count = pattern.subn(lambda _: new_block, text, count=1)
if count != 1:
    raise SystemExit(f"expected 1 replacement, got {count}")
path.write_text(text_new, encoding="utf-8")
