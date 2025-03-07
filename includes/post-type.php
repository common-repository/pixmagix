<?php

namespace AndrasWeb\PixMagix;

use function AndrasWeb\PixMagix\Utils\get_json_data;
use function AndrasWeb\PixMagix\Utils\get_upload_dir;
use function AndrasWeb\PixMagix\Utils\get_file_extension;
use function AndrasWeb\PixMagix\Utils\create_image_from_base64;
use function AndrasWeb\PixMagix\Utils\is_base64;
use function AndrasWeb\PixMagix\Settings\get_setting;

// Exit, if accessed directly.

if (!defined('ABSPATH')){
	exit;
}

/**
 * Register PixMagix post type, and its post meta.
 * This post type is used to save graphics as a project.
 * As well as, we register 'pixmagix_revision_url' post meta
 * for attachments to can be restored.
 * @since 1.0.0
 * @final
 */

final class Post_Type {

	/**
	 * Constructor.
	 * @since 1.0.0
	 * @access public
	 */

	public function __construct(){
		add_action('init', array($this, 'register'), 99, 0);
		add_action('rest_insert_pixmagix', array($this, 'create_images'), 99, 3);
		add_action('rest_delete_pixmagix', array($this, 'delete_images'), 99, 2);
		add_filter('rest_attachment_query', __NAMESPACE__ . '\\Rest\Utils\add_date_arg', 99, 2);
		add_filter('rest_pixmagix_query', __NAMESPACE__ . '\\Rest\Utils\add_date_arg', 99, 2);
		add_filter('rest_pixmagix_ai_arch_query', __NAMESPACE__ . '\\Rest\Utils\add_date_arg', 99, 2);
	}

	/**
	 * Register the post type, and post metas.
	 * @since 1.0.0
	 * @access public
	 */

	public function register(){
		register_post_type(
			'pixmagix',
			array(
				'label' => esc_html__('PixMagix', 'pixmagix'),
				'public' => false,
				'show_in_rest' => true,
				'supports' => array(
					'title',
					'editor', // To save project description.
					'custom-fields',
					'author'
				),
				'taxonomies' => array(
					'pixmagix_category'
				),
				'rewrite' => false,
				'query_var' => false,
				'can_export' => false,
				'rest_controller_class' => __NAMESPACE__ . '\\Rest\\Post_Controller',
				'capability_type' => 'pixmagix',
				'map_meta_cap' => false
			)
		);
		register_post_meta(
			'pixmagix',
			'pixmagix_project',
			array(
				'type' => 'object',
				'single' => true,
				'show_in_rest' => array(
					'schema' => get_json_data('project-schema')
				)
			)
		);
		register_taxonomy(
			'pixmagix_category',
			'pixmagix',
			array(
				'public' => false,
				'show_in_rest' => true,
				'hierarchical' => false,
				'rewrite' => false,
				'query_var' => false,
				'rest_controller_class' => __NAMESPACE__ . '\\Rest\\Terms_Controller'
			)
		);
		register_post_meta(
			'attachment',
			'pixmagix_revision_url',
			array(
				'type' => 'string',
				'single' => true,
				'show_in_rest' => true
			)
		);
		// Register post type, and meta for archive of ai generated images.
		register_post_type(
			'pixmagix_ai_arch',
			array(
				'label' => esc_html__('PixMagix AI Archives', 'pixmagix'),
				'public' => false,
				'show_in_rest' => true,
				'supports' => array(
					'title',
					'custom-fields',
					'author'
				),
				'rewrite' => false,
				'query_var' => false,
				'can_export' => false,
				'rest_controller_class' => __NAMESPACE__ . '\\Rest\\AI_Arch_Controller',
				'capability_type' => 'pixmagix',
				'map_meta_cap' => false
			)
		);
		register_post_meta(
			'pixmagix_ai_arch',
			'pixmagix_ai_arch_project',
			array(
				'type' => 'object',
				'single' => true,
				'show_in_rest' => array(
					'schema' => $this->get_ai_arch_schema()
				)
			)
		);
	}

	/**
	 * Creating asset files for the saved projects such as thumbnail image, or optionally image layers.
	 * @since 1.0.0
	 * @access public
	 * @param WP_Post $post
	 * @param WP_REST_Request $request
	 * @param bool $creating
	 */

	public function create_images($post, $request, $creating){

		if (!current_user_can('upload_files')){
			return;
		}

		/**
		 * If you set it to false, please note that the base64 encoded images
		 * will be saved to the database that are - sometimes - extremely large strings.
		 * Some MySQL server configuration do not allow to save too large strings.
		 * @since 1.0.0
		 * @param bool $allow
		 */

		$allow_save_image = apply_filters('pixmagix_allow_save_layers_as_image', true);
		$has_meta = $request->has_param('meta');

		if (!$allow_save_image || !$has_meta){
			return;
		}

		$id = $post->ID;
		$meta = $request->get_param('meta');
		$project = isset($meta['pixmagix_project']) ? $meta['pixmagix_project'] : array();

		if (empty($project) || empty($id)){
			return;
		}

		$thumbnail = isset($project['thumbnail']) ? $project['thumbnail'] : '';
		$preview = isset($project['preview']) ? $project['preview'] : '';
		$layers = isset($project['layers']) ? (array) $project['layers'] : array();
		$new_layers = array();

		// Remove old layers when we update a project.
		if (!$creating){
			$this->_remove_old_layers($id, $layers);
		}

		// Create thumbnail image
		if (is_base64($thumbnail)){
			$filename = 'project-' . $id . '.jpg';
			$meta['pixmagix_project']['thumbnail'] = esc_url_raw(create_image_from_base64($thumbnail, 'thumbnails', $filename));
		}

		// Create preview image.
		if (is_base64($preview)){
			$filename = 'project-' . $id . '.jpg';
			$meta['pixmagix_project']['preview'] = esc_url_raw(create_image_from_base64($preview, 'previews', $filename));
		}

		// Create layer images.
		if (!empty($layers)){
			foreach ($layers as $layer){
				if ($layer['type'] === 'image' && isset($layer['src']) && is_base64($layer['src'])){
					$layer_id = $layer['id'];
					$filename = 'layer-' . $id . '-' . $layer_id . '.png';
					$layer['src'] = esc_url_raw(create_image_from_base64($layer['src'], 'layers', $filename));
				}
				$new_layers[] = $layer;
			}
		}

		$meta['pixmagix_project']['layers'] = $new_layers;

		$request->set_param('meta', $meta);

	}

	/**
	 * Delete all asset files on project deleted.
	 * @since 1.0.0
	 * @access public
	 * @param WP_Post $post
	 * @param WP_REST_Response $response
	 */

	public function delete_images($post, $response){

		if (!current_user_can('upload_files')){
			return;
		}

		$data = $response->get_data();
		$id = $data['previous']['id'] ?? 0;
		$id = absint($id);
		$meta = $data['previous']['meta'] ?? array();
		$project = $meta['pixmagix_project'] ?? array();
		$layers = $project['layers'] ?? array();

		if (empty($id) || empty($project)){
			return;
		}

		$thumbnail = get_upload_dir('thumbnails', 'project-' . $id . '.jpg');
		$preview = get_upload_dir('previews', 'project-' . $id . '.jpg');
		if (file_exists($thumbnail)){
			wp_delete_file($thumbnail);
		}
		if (file_exists($preview)){
			wp_delete_file($preview);
		}

		if (!empty($layers)){
			foreach ($layers as $layer){
				$layer_id = $layer['id'];
				if ($layer['type'] === 'image'){
					$extension = get_file_extension($layer['src'] ?? '', 'png');
					$file = get_upload_dir('layers', 'layer-' . $id . '-' . $layer_id . '.' . $extension);
					if (file_exists($file)){
						wp_delete_file($file);
					}
				}
			}
		}

	}

	/**
	 *
	 * @since 1.0.0
	 * @access private
	 * @param int $id Project id.
	 * @param array $layers
	 */

	private function _remove_old_layers($id, $layers){

		if (empty($id)){
			return;
		}

		$dir = get_upload_dir('layers');
		$files = @scandir($dir);
		$layer_ids = array_map(function($layer){
			return $layer['id'] ?? '';
		}, $layers);

		if (!empty($files)){
			foreach ($files as $filename){
				$layer_id = str_replace(
					array(
						'layer-' . $id . '-',
						'.png',
						'.jpg'
					),
					'',
					$filename
				);
				// The filenames of layers are 'layer-{$post_id}-${$layer_id}.png'.
				// The $layer_id starts with 'pixmagix-'.
				// Here, we search for files that belong to the updated post,
				// and delete it if it was deleted from the layers list.
				if (strpos($filename, '-' . $id . '-pixmagix') !== false && !in_array($layer_id, $layer_ids)){
					wp_delete_file($dir . $filename);
				}
			}
		}

	}

	/**
	 * 
	 * @since 1.2.0
	 * @access private
	 * @return array
	 */

	private function get_ai_arch_schema(){
		return array(
			'type' => 'object',
			'properties' => array(
				'generator' => array(
					'type' => 'string',
					'enum' => array('openai', 'stabilityai')
				),
				'style' => array(
					'type' => 'string'
				),
				'model' => array(
					'type' => 'string'
				),
				'size' => array(
					'type' => 'string'
				),
				'quality' => array(
					'type' => 'string'
				),
				'samplesCount' => array(
					'type' => 'number'
				),
				'prompts' => array(
					'type' => 'array',
					'items' => array(
						'type' => 'object',
						'properties' => array(
							'id' => array(
								'type' => 'string'
							),
							'text' => array(
								'type' => 'string'
							),
							'weight' => array(
								'type' => 'number'
							)
						)
					)
				),
				'samples' => array(
					'type' => 'array',
					'items' => array(
						'type' => 'object',
						'properties' => array(
							'id' => array(
								'type' => 'string'
							),
							'src' => array(
								'type' => 'string'
							)
						)
					)
				)
			)
		);
	}

}

?>
