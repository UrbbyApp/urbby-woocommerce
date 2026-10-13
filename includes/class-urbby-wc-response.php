<?php
/**
 * Respuesta de la API de Urbby.
 *
 * @package UrbbyWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Respuesta de Urbby. `status` 0 = no hubo respuesta (red caída, timeout): eso NUNCA se lee como éxito.
 */
final class Urbby_WC_Response {

	/** @var int */
	public $status;

	/** @var array|null */
	public $body;

	/** @var string|null */
	public $network_error;

	/**
	 * @param int         $status        Código HTTP, 0 sin respuesta.
	 * @param array|null  $body          Cuerpo JSON.
	 * @param string|null $network_error Motivo cuando no hubo respuesta.
	 */
	public function __construct( $status, $body, $network_error = null ) {
		$this->status        = (int) $status;
		$this->body          = is_array( $body ) ? $body : null;
		$this->network_error = $network_error;
	}

	/** @return bool 2xx CON cuerpo JSON: un 200 con HTML (un proxy, una página de error) no es una respuesta de Urbby. */
	public function ok() {
		return $this->status >= 200 && $this->status < 300 && null !== $this->body;
	}

	/** @return bool Sin respuesta, error de Urbby o respuesta que no es de Urbby: se puede reintentar. */
	public function retryable() {
		return 0 === $this->status || $this->status >= 500 || 429 === $this->status || ( $this->status < 300 && null === $this->body );
	}

	/** @return string El `code` del error de Urbby, o '' si no hay. */
	public function code() {
		return isset( $this->body['code'] ) ? (string) $this->body['code'] : '';
	}

	/** @return string Un mensaje para mostrar. */
	public function message() {
		if ( 0 === $this->status ) {
			return 'Urbby no respondió: ' . $this->network_error;
		}
		return isset( $this->body['message'] ) ? (string) $this->body['message'] : 'Urbby respondió ' . $this->status . '.';
	}
}
