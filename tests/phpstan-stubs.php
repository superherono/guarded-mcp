<?php
/**
 * PHPStan stubs for code that is not installed with Composer: the optional
 * integrations (Polylang, Yoast SEO) and the MCP Adapter plugin.
 *
 * Only what this plugin calls. At runtime every call sits behind a
 * function_exists() / class_exists() check or inside the mcp_adapter_init hook.
 * Signatures follow the public APIs of Polylang 3, Yoast SEO and MCP Adapter 0.7.
 * Read by PHPStan only (scanFiles), never loaded by WordPress.
 *
 * @package guarded-mcp
 */

// phpcs:disable

namespace {
	/**
	 * @param array<string,mixed> $args
	 * @return array<int,mixed>
	 */
	function pll_languages_list( $args = array() ) { return array(); }

	/** @return string|false */
	function pll_default_language( $field = 'slug' ) { return 'en'; }

	/** @return string|false */
	function pll_get_post_language( $post_id, $field = 'slug' ) { return 'en'; }

	/** @return bool */
	function pll_set_post_language( $id, $lang ) { return true; }

	/** @return int|false|null */
	function pll_get_post( $post_id, $lang = '' ) { return 0; }

	/** @return string|false */
	function pll_get_term_language( $term_id, $field = 'slug' ) { return 'en'; }

	/** @return array<string,int> */
	function pll_get_term_translations( $term_id ) { return array(); }

	/** @return void */
	function pll_set_term_language( $id, $lang ) {}

	/**
	 * @param array<string,int> $arr
	 * @return void
	 */
	function pll_save_term_translations( $arr ) {}

	/** @return bool */
	function pll_is_translated_post_type( $post_type ) { return true; }

	/** @return bool */
	function pll_is_translated_taxonomy( $taxonomy ) { return true; }

	class WPSEO_Meta {
		/** @return mixed */
		public static function get_value( $key, $postid = 0 ) { return ''; }

		/** @return bool */
		public static function set_value( $key, $meta_value, $post_id ) { return true; }

		/** @return bool */
		public static function delete( $key, $post_id ) { return true; }
	}
}

namespace WP\MCP\Transport {
	class HttpTransport {}
}

namespace WP\MCP\Infrastructure\ErrorHandling {
	class ErrorLogMcpErrorHandler {}
}

namespace WP\MCP\Infrastructure\Observability {
	class NullMcpObservabilityHandler {}
}
