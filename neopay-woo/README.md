# NeoPay para WooCommerce (neopay-woo)

Pasarela NeoNet **NeoPay** para WooCommerce. Autor: Jonathan Diaz — [soydiaz.com](https://www.soydiaz.com).

Integración con el API REST de NeoPay con 3-D Secure.

## Funcionalidades

| Requisito NeoNet (manual, pág. 5) | Implementación |
|---|---|
| Venta (0200) | REST 3DS (pasos 1 → DDC → 3 → Step-Up → 5) |
| Reversas automáticas (0400) | Si NeoNet no responde (timeout/5xx) se envía la reversa con el mismo número de auditoría |
| Anulaciones | Cambiar el pedido al estado por defecto de WooCommerce **Cancelado**: `MessageTypeId 0200` + `ProcessingCode 020000` con la auditoría de la venta. Si NeoNet no la aprueba o está fuera de horario, el pedido vuelve a su estado anterior |
| Vouchers de venta y anulación | Página de gracias, "Ver pedido", correos y versión imprimible (anulación en negativo) |
| NeoCuotas (opcional) | `additionalData = VC03/06/10/12/18/24`, configurable con monto mínimo |

Además:

- **Dos ambientes** (Pruebas / Producción) con credenciales separadas. Cada pedido guarda el ambiente con que se cobró; la anulación usa siempre ese mismo.
- **Checkout clásico y por bloques**, página "pagar pedido", HPOS.
- **Detalle en el pedido**: caja "NeoPay: detalle de la transacción" con auditoría, referencia, autorización, código de respuesta, 3DS, tarjeta enmascarada, cuotas y el historial de cada llamada al API con su respuesta JSON (enmascarada).
- **Logs**: *WooCommerce → NeoPay Logs* (tabla propia, filtros, detalle request/response, exportación CSV, retención configurable) + log de WooCommerce `neopay-woo`.
- **Nunca se guarda la tarjeta completa**: PAN como `400000******5944`, sin CVV, sin vencimiento, sin contraseña del comercio; los JWT se truncan y se borran del pedido al terminar.
- Número de auditoría correlativo 000001–999999 por ambiente, atómico en MySQL.
- Aprobación estricta según el manual: solo `ResponseCode = 00` (con `TypeOperation = 1`) marca el pedido como pagado y solo `00` confirma una anulación; cualquier otro código es denegado.
- Conexión a NeoNet con HTTPS, verificación de certificado y **TLS 1.2 como mínimo**.
- Bloqueos para que los pasos 3 y 5 nunca se envíen dos veces (recargas, doble clic, retorno duplicado del ACS).

## Instalación

1. Subir `neopay-woo.zip` en *Plugins → Añadir nuevo*, o copiar la carpeta a `wp-content/plugins/`.
2. Activar. Se crea la tabla `{prefix}neopay_woo_logs`.
3. *WooCommerce → Ajustes → Pagos → NeoPay (NeoNet)*.

## Configuración

- **Ambiente**: Pruebas o Producción.
- **Credenciales por ambiente**: URL REST, `merchantUser`, `merchantPasswd`, `terminalId`, afiliación (`CardAcqId`), IP pública del servidor (`merchantServerIP`) e IP de la pasarela (`paymentgwIP`).
  - URL de pruebas precargada: `https://epaytestvisanet.com.gt:4433/V3/api/AuthorizationPaymentCommerce`.
  - URL de producción precargada: `https://epayserver.neonet.com.gt/api/AuthorizationPaymentCommerce`.
  - Usuario, contraseña, terminal y afiliación vienen vacíos: son los que NeoNet entrega al comercio.
- Monedas: GTQ y USD (filtro `neopay_woo_supported_currencies`).

## Datos de facturación (BillTo) y entrega (ShipTo)

Cada dato se toma del primer lugar donde tenga valor:

1. Campo estándar de WooCommerce del mismo tipo (facturación para BillTo, envío para ShipTo).
2. Campo estándar de WooCommerce del otro tipo.
3. Campo personalizado indicado en *Ajustes → Datos de facturación y entrega* (clave de meta del pedido o del cliente, varias separadas por comas).
4. Campo personalizado detectado automáticamente en el pedido o en el perfil del cliente: plugins de campos de checkout, campos adicionales del checkout por bloques (`_wc_billing/…`, `_wc_other/…`), etc. Se puede desactivar.

Si no aparece en ningún lado se envía vacío; no se envían datos genéricos. En el pedido, la caja de NeoPay muestra "Origen de los datos de facturación y entrega" con la fuente de cada campo y cuáles se enviaron vacíos. Filtros: `neopay_woo_bill_to` y `neopay_woo_ship_to`.

## Anulación (estado "Cancelado")

1. En el pedido, cambiar el estado a **Cancelado** y guardar. Solo aplica a pedidos con una venta NeoPay aprobada; los pedidos sin pagar (por ejemplo los que WooCommerce cancela solos al vencer la reserva de stock) no llaman a NeoNet.
2. Ventana permitida (en la zona horaria del sitio): menos de **20 horas** desde el pago **y** pago hecho antes de las **22:00**, o, si el pago fue después de las 22:00, anulación el mismo día.
3. Respuesta `00` → se guardan auditoría, referencia y autorización de la anulación, nota en el pedido y comprobante de anulación (monto negativo).
4. Cualquier otro resultado → aviso en el admin con el mensaje del código (35, 36, 37, 38, 19, 96) y el pedido regresa a su estado anterior.

La regla se puede ajustar con el filtro `neopay_woo_void_in_window`.

### Tarjetas de prueba (archivo "Parámetros de Prueba")

| Tarjeta | Vence | CVV |
|---|---|---|
| 4000 0000 0000 0416 (Visa) | 01/29 (2901) | 123 |
| 4000 0000 0000 5944 (Visa) | 01/29 | 123 |
| 2223 0000 1002 5549 (Mastercard) | 01/29 | 123 |

## Flujo REST 3-D Secure

```
process_payment ──paso 1──▶ NeoNet (ReferenceId + JWT + URL DDC)
   └─▶ ?wc-api=neopay_woo&np=ddc     iframe oculto envía el JWT al DDC
   └─▶ np=step3 ──paso 3──▶ aprobado (sin fricción) ─▶ gracias
                          └▶ Step-Up (paso 4) ─▶ np=step4 iframe del emisor
                                                   └▶ np=acs (UrlCommerce) ─▶ np=step5 ──paso 5──▶ gracias
```

El estado vive en el pedido (no en la sesión PHP) porque el retorno del ACS es un POST de otro dominio. Todas las URL llevan el `order_key`.

## Metadatos del pedido

`_neopay_environment`, `_neopay_status` (aprobada/rechazada/anulada), `_neopay_audit_number`, `_neopay_reference_number`, `_neopay_authorization_number`, `_neopay_response_code`, `_neopay_message_type`, `_neopay_type_operation`, `_neopay_3ds_status`, `_neopay_card_brand`, `_neopay_card_last4`, `_neopay_card_masked`, `_neopay_cardholder`, `_neopay_installments`, `_neopay_additional_data`, `_neopay_amount_minor`, `_neopay_merchant`, `_neopay_terminal_id`, `_neopay_transaction_time`, `_neopay_remote_time`, `_neopay_void_*`, `_neopay_audit` (historial de llamadas).

## Hooks

- `do_action( 'neopay_woo_payment_approved', $order, $result )` — para integraciones externas (ERP, facturación, etc.).
- `do_action( 'neopay_woo_payment_failed', $order, $result )`
- `do_action( 'neopay_woo_payment_voided', $order, $result )`
- `apply_filters( 'neopay_woo_bill_to', $bill_to, $order, $sources )` y `apply_filters( 'neopay_woo_ship_to', $ship_to, $order, $sources )`
- `apply_filters( 'neopay_woo_voucher_rows', $rows, $order, $type )`
- `apply_filters( 'neopay_woo_supported_currencies', array( 'GTQ', 'USD' ) )`

## Notas para el programador y NeoNet

Decisiones de implementación que no vienen del manual ni de una respuesta del API. En el código están marcadas con `NOTA NEONET (confirmar)` o `NOTA PROGRAMADOR`.

### Para confirmar con NeoNet

| # | Nota | Archivo |
|---|---|---|
| 1 | Se envía reversa 0400 si el paso 1 no responde. | `class-neopay-woo-gateway.php` (`process_rest`) |
| 2 | Se asume que el paso 1 puede devolver la venta ya autorizada (TypeOperation 1 + 00) sin 3DS. | `class-neopay-woo-gateway.php` (`process_rest`) |
| 3 | Un HTTP 5xx se trata como venta en duda y dispara la reversa, igual que un timeout. | `class-neopay-woo-api.php` (`rest_post`) |
| 4 | Si el navegador no confirmó el DDC, el paso 3 se envía de todas formas. | `class-neopay-woo-3ds.php` (`step3`) |
| 5 | Si un dato de facturación o entrega no existe en el pedido se envía vacío (no hay valores de respaldo). Confirmar que el API lo acepta. | `class-neopay-woo-address.php` |
| 5b | ShipTo se envía con los datos de entrega del pedido. | `class-neopay-woo-api.php` (`rest_step1`) |
| 6 | Formato de OrderInformation: `ORDER-<pedido>-A1`. | `class-neopay-woo-api.php` (`order_information`) |
| 7 | Si paymentgwIP queda vacía se envía la IP del servidor. | `class-neopay-woo-gateway.php` (`get_api`) |
| 8 | Monedas habilitadas: GTQ y USD. Confirmar que la afiliación acepte USD. | `class-neopay-woo-gateway.php` (`is_available`) |
| 9 | La ventana de anulación (20 h) permite intentar anular después del cierre de las 22:00; NeoNet respondería 35/36 y el pedido vuelve a su estado anterior. | `class-neopay-woo-void.php` (`in_window`) |
| 10 | El firewall de NeoNet debe habilitar la IP pública del servidor de WordPress. | Configuración |

### Para el programador

| # | Nota | Archivo |
|---|---|---|
| 1 | La sesión 3DS vence a los 30 minutos; después el pedido queda fallido y el cliente reintenta. | `class-neopay-woo-3ds.php` (`CTX_TTL`) |
| 2 | Si el DDC no avisa en 15 segundos se continúa al paso 3. | `class-neopay-woo-3ds.php` (`ddc_page`) |
| 3 | En pruebas se acepta una URL de Step-Up con HTTP (para simuladores locales); en producción debe ser HTTPS. | `class-neopay-woo-3ds.php` (`step3`) |
| 4 | Para los códigos 41, 43, 57, 58, 89, 93 y 96 el cliente ve un mensaje genérico; el código real queda en el pedido y en los logs. | `class-neopay-woo-helper.php` (`$generic_codes`) |
| 5 | La ventana de anulación se cuenta desde la fecha de pago (si no existe, desde la creación del pedido). | `class-neopay-woo-void.php` (`reference_time`) |
