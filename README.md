# Urbby para WooCommerce

Plugin de WordPress para vender el catálogo de una tienda WooCommerce en [Urbby](https://urbby.app).

**El stock vive en Urbby.** La tienda WooCommerce y Urbby descuentan del mismo lugar, así que nunca venden la misma unidad dos veces.

## Qué hace

| Qué | Dirección | Cómo |
|---|---|---|
| Catálogo: nombre, descripción, fotos, precio, oferta | Woo → Urbby | Al guardar un producto, y con el botón «Enviar catálogo». |
| Stock inicial | Woo → Urbby | Una sola vez, la primera vez que un producto llega a Urbby (la migración). |
| Venta en la tienda Woo | Woo → Urbby | En el checkout el plugin **aparta** el stock en Urbby. Si no hay, la compra no pasa. Al pagarse, la reserva se **confirma**. |
| Cambio de stock a mano en Woo (llegó mercadería) | Woo → Urbby | Viaja como **diferencia** («+10»), nunca como número fijo, así no pisa ventas. |
| Pedido de Urbby | Urbby → Woo | Llega por webhook firmado y crea una orden de Woo, a precio de compra, **sin datos del comprador** (solo la referencia del pedido de Urbby). Las promociones de Urbby no tocan Woo. |
| Estado del pedido | Urbby → Woo | Procesando → Preparando (Urbby) → Lista para recolección (Urbby) → En camino (Urbby) → Completada (con fecha de pago; «Contra entrega (Urbby)» si fue contra entrega) / Cancelada. |
| Cancelar una orden de Urbby en Woo | Woo → Urbby | Se avisa a Urbby en segundo plano (con reintentos): cancela con las mismas reglas que desde el portal. Si Urbby no puede (ya está lista para recoger, recogida o entregada), la orden de Woo vuelve a su estado anterior y queda una nota con el motivo. |
| Desconectar, desactivar o desinstalar el plugin | Woo → Urbby | Revoca la credencial; Urbby pasa a borrador los productos de la tienda (no borra nada) y borra el webhook. |
| Stock que muestra Woo | Urbby → Woo | Es una copia de Urbby. Se pone al día después de cada venta y cada 30 minutos. |

Cada 30 minutos el plugin también revisa los pedidos recientes de Urbby y crea los que un webhook no haya traído.

La orden de Woo de un pedido de Urbby es un registro:

- **No cobra:** el cobro y la liquidación viven en Urbby.
- **No descuenta stock:** Urbby ya lo descontó al vender.
- **No le escribe al comprador ni guarda sus datos:** es cliente de Urbby, que coordina la entrega.

## Requisitos

- WordPress 6.4+ y WooCommerce 8.0+ (probado con WordPress 7.1 y WooCommerce 11.2, con y sin HPOS).
- PHP 7.4+.
- Una cuenta de comercio aprobada en Urbby y el sitio por **https**. «Conectar con Urbby» crea la credencial y el webhook solo; también se puede pegar una credencial a mano (opción avanzada), con los permisos `catalog:read`, `catalog:write`, `inventory:write`, `orders:read` y `orders:write`.
- Cada producto (o cada variación) con **«Gestionar inventario»** activado: Urbby necesita la cantidad. Se admiten productos simples y variables: hasta 3 atributos y 12 variaciones, cada variación con su propio stock.

## Instalación

1. Descargá el `.zip` del último [release](https://github.com/UrbbyApp/urbby-woocommerce/releases) y subilo en **Plugins → Añadir nuevo → Subir plugin**.
2. Abrí **WooCommerce → Urbby** y tocá **Conectar con Urbby**. Te lleva al portal para que el dueño del comercio autorice (ve el dominio de tu sitio) y vuelve ya conectado: la credencial y el webhook se crean solos y se guardan como si se hubieran pegado a mano. Al terminar se encola el envío del catálogo completo.
3. Elegí la categoría de Urbby de cada categoría de tu tienda (o una por defecto) y guardá. Lo que no se pueda enviar aparece con su motivo en la columna «Urbby» de la lista de productos.
4. **Probar conexión** revisa cada permiso que el plugin necesita, sin escribir nada.

**Avanzado (v0.1.0 sigue funcionando):** en «Avanzado: pegar la credencial a mano» se pega el Client ID y el Client secret del portal, y se crea en el portal un webhook a `https://tu-tienda/wp-json/urbby/v1/webhook` con los eventos `order_created`, `order_status_changed` y `shipment_status_changed`, cuyo secreto se pega también. Los comercios conectados así no cambian; para recibir los pedidos sin datos del comprador hay que conectar con **Conectar con Urbby**.

## Seguridad de la conexión

El plugin es un cliente público (no puede guardar un secreto de app), así que la conexión usa PKCE `S256`: el `code_verifier` se genera con `random_bytes`, se guarda en el servidor (un transient de 15 minutos atado al usuario y al `state`) y nunca viaja por el navegador; el `state` se compara con `hash_equals` y se usa una sola vez. La vuelta del portal vuelve a la misma pantalla del plugin (`redirect_uri` exacto). El portal y el API se cambian solo desde `wp-config.php` (`URBBY_WC_PORTAL_BASE`, `URBBY_WC_API_BASE`).

## Lo que la v1 no hace (a propósito)

- **Reembolsos parciales:** un reembolso total devuelve el stock en Urbby; uno parcial no. Ajustá el stock a mano si la mercadería volvió.
- **Cambiar cantidades de una línea de Urbby en el admin de Woo** no mueve el stock de Urbby. El pedido se maneja desde Urbby.
- **Copias del sitio:** si la dirección del sitio cambia (un staging clonado, un backup restaurado en otro dominio), el plugin deja de hablar con Urbby hasta que se vuelva a guardar la conexión. Así una copia no vende ni mueve el stock real.
- **Otro API:** para pruebas, el API se cambia con `define( 'URBBY_WC_API_BASE', 'https://…/v1' );` en `wp-config.php`. No se puede cambiar desde la pantalla.
- **Desinstalar** revoca la credencial en Urbby y la borra de acá, pero conserva el vínculo de cada producto. Al reinstalar no se vuelve a migrar el stock, que pisaría el de Urbby.
- **Credencial revocada** (por ejemplo, «Desconectar» en el portal): el plugin se entera en su próxima llamada (401), lo avisa en su pantalla y, mientras tanto, la tienda vende con su propio stock. Se arregla con **Conectar con Urbby**.
- **Facturación (DTE):** un plugin de facturación de WordPress puede seguir facturando cuando la orden pasa a «Completada». Queda abierto.

## Desarrollo

```bash
composer install
composer test    # PHPUnit: las reglas puras (estados, firma, montos, referencias)
composer lint    # WordPress Coding Standards + compatibilidad con PHP 7.4
```

Los textos están en español con el dominio `urbby-woocommerce` (traducibles); `readme.txt` está en inglés, listo para WordPress.org. La versión `v0.2.0` se publica con un tag `v*` (el CI arma el `.zip`).

La prueba de punta a punta (WordPress + WooCommerce reales contra el API) vive junto al API de Urbby.

## Licencia

GPL-2.0-or-later. Ver [LICENSE](LICENSE).
