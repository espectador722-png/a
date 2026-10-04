<?php
/**
 * Plugin Name: Eclipse Zone Core
 * Description: Tipo de contenido "juego", taxonomías, campos, importador de juegos.json y datos estructurados para Eclipse Zone.
 * Version:     0.1.0
 * Author:      Eclipse Zone
 * Text Domain: eclipse-zone
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EZC_PATH', plugin_dir_path( __FILE__ ) );
define( 'EZC_URL', plugin_dir_url( __FILE__ ) );
define( 'EZC_VERSION', '0.1.0' );

require_once EZC_PATH . 'includes/content-types.php';
require_once EZC_PATH . 'includes/meta.php';
require_once EZC_PATH . 'includes/mangas.php';
require_once EZC_PATH . 'includes/usuarios.php';
require_once EZC_PATH . 'includes/importer.php';
require_once EZC_PATH . 'includes/seo.php';

// Las URLs /juego/<slug>/ y /juegos/page/N/ necesitan regenerar las reglas
// de reescritura al activar/desactivar el plugin.
register_activation_hook( __FILE__, function () {
	ezc_register_content_types();
	ezc_register_mangas();
	flush_rewrite_rules();
} );
register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );
