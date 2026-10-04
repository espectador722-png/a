<?php
/**
 * Plugin Name: Eclipse Zone Core
 * Description: Tipo de contenido "juego", taxonomías, campos, importador de juegos.json y datos estructurados para Eclipse Zone.
 * Version:     0.5.0
 * Author:      Eclipse Zone
 * Text Domain: eclipse-zone
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EZC_PATH', plugin_dir_path( __FILE__ ) );
define( 'EZC_URL', plugin_dir_url( __FILE__ ) );
define( 'EZC_VERSION', '0.5.0' );

require_once EZC_PATH . 'includes/content-types.php';
require_once EZC_PATH . 'includes/meta.php';
require_once EZC_PATH . 'includes/mangas.php';
require_once EZC_PATH . 'includes/usuarios.php';
require_once EZC_PATH . 'includes/estadisticas.php';
require_once EZC_PATH . 'includes/comunidad.php';
require_once EZC_PATH . 'includes/patreon.php';
require_once EZC_PATH . 'includes/filtros.php';
require_once EZC_PATH . 'includes/redirecciones.php';
require_once EZC_PATH . 'includes/imagenes.php';
require_once EZC_PATH . 'includes/importer.php';
require_once EZC_PATH . 'includes/seo.php';
require_once EZC_PATH . 'includes/indexnow.php';
require_once EZC_PATH . 'includes/compat.php';

// Las URLs /juego/<slug>/ y /juegos/page/N/ necesitan regenerar las reglas
// de reescritura al activar/desactivar el plugin.
register_activation_hook( __FILE__, function () {
	ezc_register_content_types();
	ezc_register_mangas();
	ezc_install_tables();
	flush_rewrite_rules();
} );
register_deactivation_hook( __FILE__, function () {
	wp_clear_scheduled_hook( 'ezc_img_cron' );
	flush_rewrite_rules();
} );

// Al actualizar el plugin subiendo archivos (sin reactivarlo) las URLs nuevas,
// como /patreon/callback/, no existirían hasta guardar los enlaces permanentes.
add_action( 'init', function () {
	if ( get_option( 'ezc_rewrite_version' ) !== EZC_VERSION ) {
		flush_rewrite_rules();
		update_option( 'ezc_rewrite_version', EZC_VERSION );
	}
}, 99 );
