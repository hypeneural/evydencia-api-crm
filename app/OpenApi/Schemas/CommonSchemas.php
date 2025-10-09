<?php
declare(strict_types=1);

namespace App\OpenApi\Schemas;
use OpenApi\Annotations as OA;

/**
 * @OA\Schema(
 *     schema="PaginationMeta",
 *     type="object",
 *     description="Metadados de paginaÃ§Ã£o e telemetria.",
 *     @OA\Property(property="page", type="integer", example=1),
 *     @OA\Property(property="per_page", type="integer", example=50, nullable=true),
 *     @OA\Property(property="total", type="integer", example=120, nullable=true),
 *     @OA\Property(property="count", type="integer", example=50, nullable=true),
 *     @OA\Property(property="total_pages", type="integer", example=3, nullable=true),
 *     @OA\Property(property="source", type="string", example="api"),
 *     @OA\Property(property="elapsed_ms", type="integer", example=42, nullable=true)
 * )
 *
 * @OA\Schema(
 *     schema="StandardLinks",
 *     type="object",
 *     @OA\Property(property="self", type="string", format="uri", example="https://api.evydencia.com/v1/resource"),
 *     @OA\Property(property="next", type="string", format="uri", nullable=true),
 *     @OA\Property(property="prev", type="string", format="uri", nullable=true)
 * )
 *
 * @OA\Schema(
 *     schema="ErrorDetail",
 *     type="object",
 *     @OA\Property(property="field", type="string", example="status"),
 *     @OA\Property(property="message", type="string", example="Valor invÃ¡lido."),
 *     @OA\Property(property="code", type="string", nullable=true, example="validation_error")
 * )
 *
 * @OA\Schema(
 *     schema="Error",
 *     type="object",
 *     required={"code","message"},
 *     @OA\Property(property="code", type="string", example="unprocessable_entity"),
 *     @OA\Property(property="message", type="string", example="Parametros invalidos."),
 *     @OA\Property(property="details", type="object", nullable=true, example={"provider_status":502,"provider_response":"Timeout while calling upstream"}),
 *     @OA\Property(
 *         property="errors",
 *         type="array",
 *         nullable=true,
 *         description="Lista opcional com erros de validacao por campo.",
 *         @OA\Items(ref="#/components/schemas/ErrorDetail")
 *     )
 * )
 *
 * @OA\Schema(
 *     schema="ErrorEnvelope",
 *     type="object",
 *     required={"success","error","trace_id"},
 *     @OA\Property(property="success", type="boolean", example=false),
 *     @OA\Property(property="error", ref="#/components/schemas/Error"),
 *     @OA\Property(property="trace_id", type="string", example="a1b2c3d4e5f6a7b8"),
 *     @OA\Property(
 *         property="meta",
 *         type="object",
 *         nullable=true,
 *         description="Contexto opcional sobre a falha",
 *         @OA\Property(property="timestamp", type="string", format="date-time", example="2025-10-03T18:20:00Z"),
 *         @OA\Property(property="correlation_id", type="string", nullable=true)
 *     )
 * )

 *
 * @OA\Schema(
 *     schema="SuccessEnvelope",
 *     type="object",
 *     required={"success","data","meta","links","trace_id"},
 *     @OA\Property(property="success", type="boolean", example=true),
 *     @OA\Property(property="meta", ref="#/components/schemas/PaginationMeta"),
 *     @OA\Property(property="links", ref="#/components/schemas/StandardLinks"),
 *     @OA\Property(property="trace_id", type="string", example="a1b2c3d4e5f6a7b8"),
 *     @OA\Property(property="data", description="Carga Ãºtil da resposta", nullable=true)
 * )
 *
 * @OA\Schema(
 *     schema="OrderStatusUpdatePayload",
 *     type="object",
 *     required={"status"},
 *     @OA\Property(property="status", type="string", enum={"waiting_product_retrieve"}, example="waiting_product_retrieve"),
 *     @OA\Property(property="note", type="string", nullable=true, example="Atualizado manualmente")
 * )
 *
 *
 * @OA\Schema(
 *     schema="GenericRecord",
 *     type="object",
 *     description="Objeto flexÃ­vel com chaves dinÃ¢micas.",
 *     additionalProperties=true
 * )
 *
 * @OA\Schema(
 *     schema="BlacklistCreatePayload",
 *     type="object",
 *     required={"whatsapp"},
 *     @OA\Property(property="name", type="string", nullable=true, example="Cliente Bloqueado", description="Opcional. Se ausente, um nome padrao sera atribuido."),
 *     @OA\Property(property="whatsapp", type="string", example="5511999999999"),
 *     @OA\Property(property="has_closed_order", type="boolean", description="Marca se o contato possui pedido fechado.", example=false),
 *     @OA\Property(property="observation", type="string", nullable=true, example="Observaï¿½ï¿½o opcional")
 * )
 *
 * @OA\Schema(
 *     schema="BlacklistUpdatePayload",
 *     type="object",
 *     description="Campos parciais para atualizar um registro da blacklist.",
 *     @OA\Property(property="name", type="string", nullable=true),
 *     @OA\Property(property="whatsapp", type="string", nullable=true),
 *     @OA\Property(property="has_closed_order", type="boolean", nullable=true),
 *     @OA\Property(property="observation", type="string", nullable=true)
 * )
 *
 *
 * @OA\Schema(
 *     schema="BlacklistResource",
 *     type="object",
 *     required={"id","name","whatsapp","has_closed_order"},
 *     @OA\Property(property="id", type="integer", example=123),
 *     @OA\Property(property="name", type="string", example="Cliente Bloqueado"),
 *     @OA\Property(property="whatsapp", type="string", example="5511999999999"),
 *     @OA\Property(property="has_closed_order", type="boolean", example=true),
 *     @OA\Property(property="observation", type="string", nullable=true, example="Chargeback em 2023"),
 *     @OA\Property(property="created_at", type="string", format="date-time", example="2025-10-03T12:00:00Z"),
 *     @OA\Property(property="updated_at", type="string", format="date-time", example="2025-10-04T09:15:00Z")
 * )
 *
 * @OA\Schema(
 *     schema="ScheduledPostCreatePayload",
 *     type="object",
 *     required={"type","scheduled_datetime"},
 *     @OA\Property(property="type", type="string", enum={"text","image","video"}, example="text"),
 *     @OA\Property(property="message", type="string", nullable=true, example="Mensagem programada"),
 *     @OA\Property(property="image_url", type="string", format="uri", nullable=true),
 *     @OA\Property(property="video_url", type="string", format="uri", nullable=true),
 *     @OA\Property(property="caption", type="string", nullable=true),
 *     @OA\Property(property="scheduled_datetime", type="string", format="date-time", example="2025-10-03T15:30:00-03:00"),
 *     @OA\Property(property="zaapId", type="string", nullable=true),
 *     @OA\Property(property="messageId", type="string", nullable=true)
 * )
 *
 * @OA\Schema(
 *     schema="ScheduledPostUpdatePayload",
 *     type="object",
 *     description="Campos parciais para atualizar um agendamento.",
 *     @OA\Property(property="type", type="string", enum={"text","image","video"}, nullable=true),
 *     @OA\Property(property="message", type="string", nullable=true),
 *     @OA\Property(property="image_url", type="string", format="uri", nullable=true),
 *     @OA\Property(property="video_url", type="string", format="uri", nullable=true),
 *     @OA\Property(property="caption", type="string", nullable=true),
 *     @OA\Property(property="scheduled_datetime", type="string", format="date-time", nullable=true),
 *     @OA\Property(property="zaapId", type="string", nullable=true),
 *     @OA\Property(property="messageId", type="string", nullable=true)
 * )
 *
 * @OA\Schema(
 *     schema="MarkScheduledPostSentPayload",
 *     type="object",
 *     required={"messageId"},
 *     @OA\Property(property="messageId", type="string", example="wamid.HBgM..."),
 *     @OA\Property(property="zaapId", type="string", nullable=true)
 * )
 *
 *
 * @OA\Schema(
 *     schema="ScheduledPostResource",
 *     type="object",
 *     required={"id","type","scheduled_datetime"},
 *     @OA\Property(property="id", type="integer", example=321),
 *     @OA\Property(property="type", type="string", enum={"text","image","video"}, example="text"),
 *     @OA\Property(property="message", type="string", nullable=true, example="Mensagem programada"),
 *     @OA\Property(property="image_url", type="string", format="uri", nullable=true),
 *     @OA\Property(property="video_url", type="string", format="uri", nullable=true),
 *     @OA\Property(property="caption", type="string", nullable=true),
 *     @OA\Property(property="scheduled_datetime", type="string", format="date-time", example="2025-10-03T15:30:00-03:00"),
 *     @OA\Property(property="zaapId", type="string", nullable=true, example="ZP-123"),
 *     @OA\Property(property="messageId", type="string", nullable=true, example="MSG-456"),
 *     @OA\Property(property="has_media", type="boolean", example=false),
 *     @OA\Property(property="created_at", type="string", format="date-time"),
 *     @OA\Property(property="updated_at", type="string", format="date-time")
 * )
 *
 * @OA\Schema(
 *     schema="WhatsAppTextPayload",
 *     type="object",
 *     required={"phone","message"},
 *     @OA\Property(property="phone", type="string", example="5511999999999"),
 *     @OA\Property(property="message", type="string", example="Olï¿½, tudo bem?")
 * )
 *
 * @OA\Schema(
 *     schema="WhatsAppAudioPayload",
 *     type="object",
 *     required={"phone","audio"},
 *     @OA\Property(property="phone", type="string"),
 *     @OA\Property(property="audio", type="string", description="Base64 ou URL pï¿½blica do ï¿½udio."),
 *     @OA\Property(property="delayMessage", type="integer", nullable=true),
 *     @OA\Property(property="delayTyping", type="integer", nullable=true),
 *     @OA\Property(property="viewOnce", type="boolean", nullable=true),
 *     @OA\Property(property="async", type="boolean", nullable=true),
 *     @OA\Property(property="waveform", type="string", nullable=true)
 * )
 *
 * @OA\Schema(
 *     schema="WhatsAppImagePayload",
 *     type="object",
 *     required={"phone","image"},
 *     @OA\Property(property="phone", type="string"),
 *     @OA\Property(property="image", type="string", description="Base64 ou URL pï¿½blica da imagem."),
 *     @OA\Property(property="caption", type="string", nullable=true),
 *     @OA\Property(property="messageId", type="string", nullable=true),
 *     @OA\Property(property="delayMessage", type="integer", nullable=true),
 *     @OA\Property(property="viewOnce", type="boolean", nullable=true)
 * )
 *
 * @OA\Schema(
 *     schema="WhatsAppDocumentPayload",
 *     type="object",
 *     required={"phone","extension","document"},
 *     @OA\Property(property="phone", type="string"),
 *     @OA\Property(property="extension", type="string", example="pdf"),
 *     @OA\Property(property="document", type="string", description="Base64 ou URL pï¿½blica do arquivo."),
 *     @OA\Property(property="fileName", type="string", nullable=true),
 *     @OA\Property(property="caption", type="string", nullable=true),
 *     @OA\Property(property="messageId", type="string", nullable=true),
 *     @OA\Property(property="delayMessage", type="integer", nullable=true),
 *     @OA\Property(property="editDocumentMessageId", type="string", nullable=true)
 * )
 *
 * @OA\Schema(
 *     schema="WhatsAppPtvPayload",
 *     type="object",
 *     required={"phone","ptv"},
 *     @OA\Property(property="phone", type="string"),
 *     @OA\Property(property="ptv", type="string", description="Base64 ou URL publica do video."),
 *     @OA\Property(property="messageId", type="string", nullable=true),
 *     @OA\Property(property="delayMessage", type="integer", nullable=true)
 * )
 *
 * @OA\Schema(
 *     schema="WhatsAppLocationPayload",
 *     type="object",
 *     required={"phone","title","address","latitude","longitude"},
 *     @OA\Property(property="phone", type="string"),
 *     @OA\Property(property="title", type="string"),
 *     @OA\Property(property="address", type="string"),
 *     @OA\Property(property="latitude", type="string"),
 *     @OA\Property(property="longitude", type="string"),
 *     @OA\Property(property="messageId", type="string", nullable=true),
 *     @OA\Property(property="delayMessage", type="integer", nullable=true)
 * )
 *
 * @OA\Schema(
 *     schema="WhatsAppLinkPayload",
 *     type="object",
 *     required={"phone","message","image","linkUrl","title","linkDescription"},
 *     @OA\Property(property="phone", type="string"),
 *     @OA\Property(property="message", type="string"),
 *     @OA\Property(property="image", type="string", description="URL da imagem"),
 *     @OA\Property(property="linkUrl", type="string", description="URL compartilhada"),
 *     @OA\Property(property="title", type="string"),
 *     @OA\Property(property="linkDescription", type="string"),
 *     @OA\Property(property="delayMessage", type="integer", nullable=true),
 *     @OA\Property(property="delayTyping", type="integer", nullable=true),
 *     @OA\Property(property="linkType", type="string", nullable=true, description="SMALL, MEDIUM ou LARGE")
 * )
 *
 * @OA\Schema(
 *     schema="WhatsAppStickerPayload",
 *     type="object",
 *     required={"phone","sticker"},
 *     @OA\Property(property="phone", type="string"),
 *     @OA\Property(property="sticker", type="string", description="Base64 ou URL publica do sticker."),
 *     @OA\Property(property="messageId", type="string", nullable=true),
 *     @OA\Property(property="delayMessage", type="integer", nullable=true),
 *     @OA\Property(property="stickerAuthor", type="string", nullable=true)
 * )
 *
 * @OA\Schema(
 *     schema="WhatsAppGifPayload",
 *     type="object",
 *     required={"phone","gif"},
 *     @OA\Property(property="phone", type="string"),
 *     @OA\Property(property="gif", type="string", description="Base64 ou URL (mp4) do GIF."),
 *     @OA\Property(property="messageId", type="string", nullable=true),
 *     @OA\Property(property="delayMessage", type="integer", nullable=true),
 *     @OA\Property(property="caption", type="string", nullable=true)
 * )
 *
 * @OA\Schema(
 *     schema="WhatsAppCarouselButton",
 *     type="object",
 *     required={"type","label"},
 *     @OA\Property(property="type", type="string", enum={"CALL","URL","REPLY"}),
 *     @OA\Property(property="label", type="string"),
 *     @OA\Property(property="phone", type="string", nullable=true),
 *     @OA\Property(property="url", type="string", format="uri", nullable=true),
 *     @OA\Property(property="id", type="string", nullable=true)
 * )
 *
 * @OA\Schema(
 *     schema="WhatsAppCarouselCard",
 *     type="object",
 *     required={"text","image"},
 *     @OA\Property(property="text", type="string"),
 *     @OA\Property(property="image", type="string", format="uri"),
 *     @OA\Property(property="buttons", type="array", @OA\Items(ref="#/components/schemas/WhatsAppCarouselButton"), nullable=true)
 * )
 *
 * @OA\Schema(
 *     schema="WhatsAppCarouselPayload",
 *     type="object",
 *     required={"phone","message","carousel"},
 *     @OA\Property(property="phone", type="string"),
 *     @OA\Property(property="message", type="string"),
 *     @OA\Property(property="carousel", type="array", @OA\Items(ref="#/components/schemas/WhatsAppCarouselCard")),
 *     @OA\Property(property="delayMessage", type="integer", nullable=true)
 * )
 *
 * @OA\Schema(
 *     schema="WhatsAppTextStatusPayload",
 *     type="object",
 *     required={"message"},
 *     @OA\Property(property="message", type="string" )
 * )
 *
 * @OA\Schema(
 *     schema="WhatsAppOptionListOption",
 *     type="object",
 *     required={"title"},
 *     @OA\Property(property="title", type="string"),
 *     @OA\Property(property="description", type="string", nullable=true),
 *     @OA\Property(property="id", type="string", nullable=true)
 * )
 *
 * @OA\Schema(
 *     schema="WhatsAppOptionListConfig",
 *     type="object",
 *     required={"title","buttonLabel","options"},
 *     @OA\Property(property="title", type="string"),
 *     @OA\Property(property="buttonLabel", type="string"),
 *     @OA\Property(property="options", type="array", @OA\Items(ref="#/components/schemas/WhatsAppOptionListOption"))
 * )
 *
 * @OA\Schema(
 *     schema="WhatsAppOptionListPayload",
 *     type="object",
 *     required={"phone","message","optionList"},
 *     @OA\Property(property="phone", type="string"),
 *     @OA\Property(property="message", type="string"),
 *     @OA\Property(property="optionList", ref="#/components/schemas/WhatsAppOptionListConfig"),
 *     @OA\Property(property="delayMessage", type="integer", nullable=true)
 * )
 *
 * @OA\Schema(
 *     schema="WhatsAppPinMessagePayload",
 *     type="object",
 *     required={"phone","messageId","messageAction"},
 *     @OA\Property(property="phone", type="string"),
 *     @OA\Property(property="messageId", type="string"),
 *     @OA\Property(property="messageAction", type="string", enum={"pin","unpin"}),
 *     @OA\Property(property="pinMessageDuration", type="string", nullable=true, enum={"24_hours","7_days","30_days"})
 * )
 *
 * @OA\Schema(
 *     schema="WhatsAppCallPayload",
 *     type="object",
 *     required={"phone"},
 *     @OA\Property(property="phone", type="string"),
 *     @OA\Property(property="callDuration", type="integer", nullable=true, minimum=1, maximum=15)
 * )
 *
 * @OA\Schema(
 *     schema="WhatsAppContactEntry",
 *     type="object",
 *     required={"firstName","phone"},
 *     @OA\Property(property="firstName", type="string"),
 *     @OA\Property(property="lastName", type="string", nullable=true),
 *     @OA\Property(property="phone", type="string")
 * )
 *
 * @OA\Schema(
 *     schema="WhatsAppContactResource",
 *     type="object",
 *     @OA\Property(property="phone", type="string"),
 *     @OA\Property(property="name", type="string", nullable=true),
 *     @OA\Property(property="short", type="string", nullable=true),
 *     @OA\Property(property="vname", type="string", nullable=true),
 *     @OA\Property(property="notify", type="string", nullable=true),
 *     @OA\Property(property="imgUrl", type="string", format="uri", nullable=true)
 * )
 *
 * @OA\Schema(
 *     schema="WhatsAppStatusImagePayload",
 *     type="object",
 *     required={"image"},
 *     @OA\Property(property="image", type="string", description="Base64 ou URL da imagem."),
 *     @OA\Property(property="caption", type="string", nullable=true)
 * )
 *
 * @OA\Schema(
 *     schema="WhatsAppStatusVideoPayload",
 *     type="object",
 *     required={"video"},
 *     @OA\Property(property="video", type="string", description="Base64 ou URL do vï¿½deo."),
 *     @OA\Property(property="caption", type="string", nullable=true)
 * )
 *
 *
 * @OA\Schema(
 *     schema="WhatsAppSendResult",
 *     type="object",
 *     description="Identificadores retornados pelo provedor Z-API.",
 *     @OA\Property(property="zaapId", type="string", nullable=true, example="zaap-123456"),
 *     @OA\Property(property="messageId", type="string", nullable=true, example="wamid.HBgMNTU5..."),
 *     additionalProperties=true
 * )
 *
 * @OA\Schema(
 *     schema="ReportDefinition",
 *     type="object",
 *     required={"key","title"},
 *     @OA\Property(property="key", type="string", example="orders-by-status"),
 *     @OA\Property(property="title", type="string", example="Pedidos por status"),
 *     @OA\Property(property="description", type="string", nullable=true),
 *     @OA\Property(property="columns", type="array", @OA\Items(type="object", additionalProperties=true)),
 *     @OA\Property(property="params", type="array", @OA\Items(type="object", additionalProperties=true))
 * )
 *
 * @OA\Schema(
 *     schema="ReportRunPayload",
 *     type="object",
 *     @OA\Property(property="success", type="boolean", example=true),
 *     @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/GenericRecord")),
 *     @OA\Property(property="summary", type="object", additionalProperties=true),
 *     @OA\Property(property="meta", type="object", additionalProperties=true),
 *     @OA\Property(property="columns", type="array", @OA\Items(type="object", additionalProperties=true)),
 *     @OA\Property(property="links", ref="#/components/schemas/StandardLinks"),
 *     @OA\Property(property="trace_id", type="string")
 * )
 *
 * @OA\Schema(
 *     schema="CampaignSchedulePayload",
 *     type="object",
 *     required={"campaign","start_at"},
 *     @OA\Property(property="campaign", type="integer", example=1234),
 *     @OA\Property(property="start_at", type="string", format="date-time", example="2025-10-03T15:30:00-03:00"),
 *     @OA\Property(property="finish_at", type="string", format="date-time", nullable=true),
 *     @OA\Property(property="contacts", type="array", description="Lista de contatos (nï¿½meros ou e-mails).", @OA\Items(type="string")),
 *     @OA\Property(property="use_leads_system", type="boolean", example=false),
 *     @OA\Property(property="instance", type="integer", nullable=true),
 *     @OA\Property(property="order", type="integer", nullable=true),
 *     @OA\Property(property="customer", type="integer", nullable=true),
 *     @OA\Property(
 *         property="product",
 *         type="object",
 *         nullable=true,
 *         @OA\Property(property="uuid", type="string", nullable=true),
 *         @OA\Property(property="reference", type="string", nullable=true),
 *         @OA\Property(property="slug", type="string", nullable=true),
 *         @OA\Property(property="name", type="string", nullable=true)
 *     ),
 *     @OA\Property(property="type", type="string", example="campaign")
 * )
 *
 *
 * @OA\Schema(
 *     schema="CampaignScheduleItem",
 *     type="object",
 *     @OA\Property(property="id", type="string", example="cmp-123"),
 *     @OA\Property(property="name", type="string", example="Campanha Outubro"),
 *     @OA\Property(property="status", type="string", example="scheduled"),
 *     @OA\Property(property="scheduled_at", type="string", format="date-time"),
 *     additionalProperties=true
 * )
 *
 * @OA\Schema(
 *     schema="CampaignScheduleListResponse",
 *     allOf={
 *         @OA\Schema(ref="#/components/schemas/SuccessEnvelope"),
 *         @OA\Schema(
 *             @OA\Property(
 *                 property="data",
 *                 type="array",
 *                 @OA\Items(ref="#/components/schemas/CampaignScheduleItem")
 *             )
 *         )
 *     }
 * )
 *
 * @OA\Schema(
 *     schema="BlacklistListResponse",
 *     allOf={
 *         @OA\Schema(ref="#/components/schemas/SuccessEnvelope"),
 *         @OA\Schema(
 *             @OA\Property(
 *                 property="data",
 *                 type="array",
 *                 @OA\Items(ref="#/components/schemas/BlacklistResource")
 *             )
 *         )
 *     }
 * )
 *
 * @OA\Schema(
 *     schema="BlacklistResourceResponse",
 *     allOf={
 *         @OA\Schema(ref="#/components/schemas/SuccessEnvelope"),
 *         @OA\Schema(@OA\Property(property="data", ref="#/components/schemas/BlacklistResource"))
 *     }
 * )
 *
 * @OA\Schema(
 *     schema="BlacklistSyncChristmasOrdersPayload",
 *     type="object",
 *     required={"total_crm_customers","inserted","updated","skipped","invalid","failures"},
 *     @OA\Property(property="total_crm_customers", type="integer", example=125),
 *     @OA\Property(property="inserted", type="integer", example=42),
 *     @OA\Property(property="updated", type="integer", example=18),
 *     @OA\Property(property="skipped", type="integer", example=65),
 *     @OA\Property(property="invalid", type="integer", example=5),
 *     @OA\Property(
 *         property="failures",
 *         type="array",
 *         @OA\Items(
 *             type="object",
 *             @OA\Property(property="index", type="integer", example=3),
 *             @OA\Property(property="reason", type="string", example="Dados incompletos ou whatsapp invalido."),
 *             @OA\Property(
 *                 property="errors",
 *                 type="array",
 *                 nullable=true,
 *                 @OA\Items(ref="#/components/schemas/ErrorDetail")
 *             )
 *         )
 *     )
 * )
 *
 * @OA\Schema(
 *     schema="BlacklistSyncChristmasOrdersResponse",
 *     allOf={
 *         @OA\Schema(ref="#/components/schemas/SuccessEnvelope"),
 *         @OA\Schema(@OA\Property(property="data", ref="#/components/schemas/BlacklistSyncChristmasOrdersPayload"))
 *     }
 * )
 *
 * @OA\Schema(
 *     schema="ScheduledPostListResponse",
 *     allOf={
 *         @OA\Schema(ref="#/components/schemas/SuccessEnvelope"),
 *         @OA\Schema(
 *             @OA\Property(
 *                 property="data",
 *                 type="array",
 *                 @OA\Items(ref="#/components/schemas/ScheduledPostResource")
 *             )
 *         )
 *     }
 * )
 *
 * @OA\Schema(
 *     schema="ScheduledPostResourceResponse",
 *     allOf={
 *         @OA\Schema(ref="#/components/schemas/SuccessEnvelope"),
 *         @OA\Schema(@OA\Property(property="data", ref="#/components/schemas/ScheduledPostResource"))
 *     }
 * )
 *
 * @OA\Schema(
 *     schema="WhatsAppSendResponse",
 *     allOf={
 *         @OA\Schema(ref="#/components/schemas/SuccessEnvelope"),
 *         @OA\Schema(@OA\Property(property="data", ref="#/components/schemas/WhatsAppSendResult"))
 *     }
 * )
 *
 * @OA\Schema(
 *     schema="WhatsAppProfilePictureResource",
 *     type="object",
 *     @OA\Property(property="link", type="string", format="uri", nullable=true)
 * )
 *
 * @OA\Schema(
 *     schema="WhatsAppProfilePictureResponse",
 *     allOf={
 *         @OA\Schema(ref="#/components/schemas/SuccessEnvelope"),
 *         @OA\Schema(@OA\Property(property="data", ref="#/components/schemas/WhatsAppProfilePictureResource"))
 *     }
 * )
 *
 * @OA\Schema(
 *     schema="WhatsAppGenericResponse",
 *     allOf={
 *         @OA\Schema(ref="#/components/schemas/SuccessEnvelope"),
 *         @OA\Schema(@OA\Property(property="data", type="object", additionalProperties=true))
 *     }
 * )
 *
 * @OA\Schema(
 *     schema="WhatsAppContactResourceResponse",
 *     allOf={
 *         @OA\Schema(ref="#/components/schemas/SuccessEnvelope"),
 *         @OA\Schema(@OA\Property(property="data", ref="#/components/schemas/WhatsAppContactResource"))
 *     }
 * )
 *
 * @OA\Schema(
 *     schema="WhatsAppContactsListResponse",
 *     allOf={
 *         @OA\Schema(ref="#/components/schemas/SuccessEnvelope"),
 *         @OA\Schema(@OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/WhatsAppContactResource")))
 *     }
 * )
 *
 * @OA\Schema(
 *     schema="GenericListResponse",
 *     allOf={
 *         @OA\Schema(ref="#/components/schemas/SuccessEnvelope"),
 *         @OA\Schema(
 *             @OA\Property(
 *                 property="data",
 *                 type="array",
 *                 @OA\Items(ref="#/components/schemas/GenericRecord")
 *             )
 *         )
 *     }
 * )
 *
 * @OA\Schema(
 *     schema="GenericResourceResponse",
 *     allOf={
 *         @OA\Schema(ref="#/components/schemas/SuccessEnvelope"),
 *         @OA\Schema(@OA\Property(property="data", ref="#/components/schemas/GenericRecord"))
 *     }
 * )
 *
 * @OA\Schema(
 *     schema="PasswordResource",
 *     type="object",
 *     required={"id","usuario","senha","link","tipo","local","verificado","ativo","created_at","updated_at"},
 *     @OA\Property(property="id", type="string", format="uuid", example="550e8400-e29b-41d4-a716-446655440000"),
 *     @OA\Property(property="usuario", type="string", example="admin@empresa.com"),
 *     @OA\Property(property="senha", type="string", example="a1b2c3:ff0011:9933aa", description="Senha criptografada ou texto puro, dependendo do endpoint."),
 *     @OA\Property(property="link", type="string", format="uri", example="https://exemplo.com/admin"),
 *     @OA\Property(property="tipo", type="string", enum={"Sistema","Rede Social","E-mail"}, example="Sistema"),
 *     @OA\Property(property="local", type="string", example="WordPress"),
 *     @OA\Property(property="verificado", type="boolean", example=true),
 *     @OA\Property(property="ativo", type="boolean", example=true),
 *     @OA\Property(property="descricao", type="string", nullable=true, example="Painel administrativo"),
 *     @OA\Property(property="ip", type="string", nullable=true, example="192.168.1.100"),
 *     @OA\Property(property="created_at", type="string", format="date-time", example="2024-01-15T10:00:00Z"),
 *     @OA\Property(property="updated_at", type="string", format="date-time", example="2024-01-20T14:30:00Z"),
 *     @OA\Property(property="created_by", type="string", nullable=true, format="uuid"),
 *     @OA\Property(property="updated_by", type="string", nullable=true, format="uuid")
 * )
 *
 * @OA\Schema(
 *     schema="PasswordCreatePayload",
 *     type="object",
 *     required={"usuario","senha","link","tipo","local"},
 *     @OA\Property(property="usuario", type="string", minLength=3, maxLength=255, example="admin@empresa.com"),
 *     @OA\Property(property="senha", type="string", minLength=6, maxLength=255, example="SenhaForte@2024"),
 *     @OA\Property(property="link", type="string", example="https://exemplo.com/admin"),
 *     @OA\Property(property="tipo", type="string", enum={"Sistema","Rede Social","E-mail"}, example="Sistema"),
 *     @OA\Property(property="local", type="string", example="WordPress"),
 *     @OA\Property(property="verificado", type="boolean", nullable=true, example=false),
 *     @OA\Property(property="descricao", type="string", nullable=true, maxLength=500),
 *     @OA\Property(property="ip", type="string", nullable=true, example="192.168.1.100")
 * )
 *
 * @OA\Schema(
 *     schema="PasswordUpdatePayload",
 *     type="object",
 *     @OA\Property(property="usuario", type="string", minLength=3, maxLength=255),
 *     @OA\Property(property="senha", type="string", minLength=6, maxLength=255),
 *     @OA\Property(property="link", type="string"),
 *     @OA\Property(property="tipo", type="string", enum={"Sistema","Rede Social","E-mail"}),
 *     @OA\Property(property="local", type="string", minLength=2, maxLength=100),
 *     @OA\Property(property="verificado", type="boolean"),
 *     @OA\Property(property="descricao", type="string", nullable=true, maxLength=500),
 *     @OA\Property(property="ip", type="string", nullable=true)
 * )
 *
 * @OA\Schema(
 *     schema="PasswordBulkPayload",
 *     type="object",
 *     required={"action","ids"},
 *     @OA\Property(property="action", type="string", enum={"verify","unverify","delete"}, example="verify"),
 *     @OA\Property(property="ids", type="array", minItems=1, @OA\Items(type="string", format="uuid"))
 * )
 *
 * @OA\Schema(
 *     schema="PasswordBulkResultItem",
 *     type="object",
 *     required={"id","success"},
 *     @OA\Property(property="id", type="string", format="uuid"),
 *     @OA\Property(property="success", type="boolean"),
 *     @OA\Property(property="error", type="string", nullable=true)
 * )
 *
 * @OA\Schema(
 *     schema="PasswordBulkResponse",
 *     allOf={
 *         @OA\Schema(ref="#/components/schemas/SuccessEnvelope"),
 *         @OA\Schema(
 *             @OA\Property(
 *                 property="data",
 *                 type="object",
 *                 required={"action","affected_rows","processed","failed","results"},
 *                 @OA\Property(property="action", type="string", example="verify"),
 *                 @OA\Property(property="affected_rows", type="integer", example=3),
 *                 @OA\Property(property="processed", type="integer", example=3),
 *                 @OA\Property(property="failed", type="integer", example=0),
 *                 @OA\Property(property="results", type="array", @OA\Items(ref="#/components/schemas/PasswordBulkResultItem"))
 *             )
 *         )
 *     }
 * )
 *
 * @OA\Schema(
 *     schema="PasswordStatsResource",
 *     type="object",
 *     required={"total","verificados","nao_verificados","por_tipo","por_plataforma","ultimas_atualizacoes","criadas_hoje","atualizadas_hoje"},
 *     @OA\Property(property="total", type="integer", example=150),
 *     @OA\Property(property="verificados", type="integer", example=120),
 *     @OA\Property(property="nao_verificados", type="integer", example=30),
 *     @OA\Property(property="por_tipo", type="object", additionalProperties=@OA\Schema(type="integer"), example={"Sistema":60,"Rede Social":55,"E-mail":35}),
 *     @OA\Property(property="por_plataforma", type="object", additionalProperties=@OA\Schema(type="integer")),
 *     @OA\Property(
 *         property="ultimas_atualizacoes",
 *         type="array",
 *         @OA\Items(
 *             type="object",
 *             required={"id","usuario","local","updated_at"},
 *             @OA\Property(property="id", type="string", format="uuid"),
 *             @OA\Property(property="usuario", type="string"),
 *             @OA\Property(property="local", type="string"),
 *             @OA\Property(property="updated_at", type="string", format="date-time")
 *         )
 *     ),
 *     @OA\Property(property="criadas_hoje", type="integer", example=5),
 *     @OA\Property(property="atualizadas_hoje", type="integer", example=12)
 * )
 *
 * @OA\Schema(
 *     schema="PasswordStatsResponse",
 *     allOf={
 *         @OA\Schema(ref="#/components/schemas/SuccessEnvelope"),
 *         @OA\Schema(@OA\Property(property="data", ref="#/components/schemas/PasswordStatsResource"))
 *     }
 * )
 *
 * @OA\Schema(
 *     schema="PasswordPlatformResource",
 *     type="object",
 *     required={"local","count"},
 *     @OA\Property(property="local", type="string", example="WordPress"),
 *     @OA\Property(property="count", type="integer", example=25)
 * )
 *
 * @OA\Schema(
 *     schema="PasswordPlatformsResponse",
 *     allOf={
 *         @OA\Schema(ref="#/components/schemas/SuccessEnvelope"),
 *         @OA\Schema(
 *             @OA\Property(
 *                 property="data",
 *                 type="array",
 *                 @OA\Items(ref="#/components/schemas/PasswordPlatformResource")
 *             )
 *         )
 *     }
 * )
 *
 * @OA\Schema(
 *     schema="PasswordCheckResponse",
 *     allOf={
 *         @OA\Schema(ref="#/components/schemas/SuccessEnvelope"),
 *         @OA\Schema(
 *             @OA\Property(
 *                 property="data",
 *                 type="object",
 *                 required={"exists","password"},
 *                 @OA\Property(property="exists", type="boolean"),
 *                 @OA\Property(
 *                     property="password",
 *                     type="object",
 *                     nullable=true,
 *                     required={"id","usuario","local","verificado"},
 *                     @OA\Property(property="id", type="string", format="uuid"),
 *                     @OA\Property(property="usuario", type="string"),
 *                     @OA\Property(property="local", type="string"),
 *                     @OA\Property(property="verificado", type="boolean")
 *                 )
 *             )
 *         )
 *     }
 * )
 *
 * @OA\Schema(
 *     schema="PasswordResourceResponse",
 *     allOf={
 *         @OA\Schema(ref="#/components/schemas/SuccessEnvelope"),
 *         @OA\Schema(@OA\Property(property="data", ref="#/components/schemas/PasswordResource"))
 *     }
 * )
 *
 * @OA\Schema(
 *     schema="PasswordListResponse",
 *     allOf={
 *         @OA\Schema(ref="#/components/schemas/SuccessEnvelope"),
 *         @OA\Schema(
 *             @OA\Property(
 *                 property="data",
 *                 type="array",
 *                 @OA\Items(ref="#/components/schemas/PasswordResource")
 *             )
 *         )
 *     }
 * )
 */
final class CommonSchemas
{
}




