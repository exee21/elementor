<?php
namespace Elementor\Modules\Ai;

use Elementor\Core\Base\Module as BaseModule;
use Elementor\Core\Common\Modules\Connect\Module as ConnectModule;
use Elementor\Core\Experiments\Manager as Experiments_Manager;
use Elementor\Core\Utils\Collection;
use Elementor\Modules\Ai\Connect\Ai;
use Elementor\Plugin;
use Elementor\User;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}
class Module extends BaseModule {

	const LAYOUT_EXPERIMENT = 'ai-layout';

	public function get_name() {
		return 'ai';
	}

	public function __construct() {
		parent::__construct();

		$this->register_layout_experiment();

		add_action( 'elementor/connect/apps/register', function ( ConnectModule $connect_module ) {
			$connect_module->register_app( 'ai', Ai::get_class_name() );
		} );

		add_action( 'elementor/ajax/register_actions', function( $ajax ) {
			$ajax->register_ajax_action( 'ai_get_user_information', [ $this, 'ajax_ai_get_user_information' ] );
			$ajax->register_ajax_action( 'ai_get_completion_text', [ $this, 'ajax_ai_get_completion_text' ] );
			$ajax->register_ajax_action( 'ai_get_edit_text', [ $this, 'ajax_ai_get_edit_text' ] );
			$ajax->register_ajax_action( 'ai_get_custom_code', [ $this, 'ajax_ai_get_custom_code' ] );
			$ajax->register_ajax_action( 'ai_get_custom_css', [ $this, 'ajax_ai_get_custom_css' ] );
			$ajax->register_ajax_action( 'ai_set_get_started', [ $this, 'ajax_ai_set_get_started' ] );
			$ajax->register_ajax_action( 'ai_set_status_feedback', [ $this, 'ajax_ai_set_status_feedback' ] );
			$ajax->register_ajax_action( 'ai_get_image_prompt_enhancer', [ $this, 'ajax_ai_get_image_prompt_enhancer' ] );
			$ajax->register_ajax_action( 'ai_get_text_to_image', [ $this, 'ajax_ai_get_text_to_image' ] );
			$ajax->register_ajax_action( 'ai_get_image_to_image', [ $this, 'ajax_ai_get_image_to_image' ] );
			$ajax->register_ajax_action( 'ai_get_image_to_image_mask', [ $this, 'ajax_ai_get_image_to_image_mask' ] );
			$ajax->register_ajax_action( 'ai_get_image_to_image_outpainting', [ $this, 'ajax_ai_get_image_to_image_outpainting' ] );
			$ajax->register_ajax_action( 'ai_get_image_to_image_upscale', [ $this, 'ajax_ai_get_image_to_image_upscale' ] );
			$ajax->register_ajax_action( 'ai_get_image_to_image_remove_background', [ $this, 'ajax_ai_get_image_to_image_remove_background' ] );
			$ajax->register_ajax_action( 'ai_get_image_to_image_replace_background', [ $this, 'ajax_ai_get_image_to_image_replace_background' ] );
			$ajax->register_ajax_action( 'ai_upload_image', [ $this, 'ajax_ai_upload_image' ] );
			$ajax->register_ajax_action( 'ai_generate_layout', [ $this, 'ajax_ai_generate_layout' ] );
			$ajax->register_ajax_action( 'ai_get_layout_prompt_enhancer', [ $this, 'ajax_ai_get_layout_prompt_enhancer' ] );
		} );

		add_action( 'elementor/editor/before_enqueue_scripts', function() {
			$this->enqueue_main_script();

			if ( $this->is_layout_active() ) {
				$this->enqueue_layout_script();
			}
		} );

		add_action( 'elementor/editor/after_enqueue_styles', function() {
			wp_enqueue_style(
				'elementor-ai-editor',
				$this->get_css_assets_url( 'modules/ai/editor' ),
				[],
				ELEMENTOR_VERSION
			);
		} );

		add_action( 'elementor/preview/enqueue_styles', function() {
			if ( $this->is_layout_active() ) {
				wp_enqueue_style(
					'elementor-ai-layout-preview',
					$this->get_css_assets_url( 'modules/ai/layout-preview' ),
					[],
					ELEMENTOR_VERSION
				);
			}
		} );

		add_filter( 'elementor/document/save/data', function ( $data ) {
			if ( $this->is_layout_active() ) {
				return $this->remove_temporary_containers( $data );
			}

			return $data;
		} );
	}

	private function register_layout_experiment() {
		Plugin::$instance->experiments->add_feature( [
			'name' => static::LAYOUT_EXPERIMENT,
			'title' => esc_html__( 'Build with AI', 'elementor' ),
			'default' => Experiments_Manager::STATE_INACTIVE,
			'status' => Experiments_Manager::RELEASE_STATUS_ALPHA,
			'hidden' => true,
			'dependencies' => [
				'container',
			],
		] );
	}

	private function enqueue_main_script() {
		wp_enqueue_script(
			'elementor-ai',
			$this->get_js_assets_url( 'ai' ),
			[
				'react',
				'react-dom',
				'backbone-marionette',
				'elementor-web-cli',
				'elementor-common',
				'elementor-editor-modules',
				'elementor-editor-document',
				'elementor-v2-ui',
				'elementor-v2-icons',
			],
			ELEMENTOR_VERSION,
			true
		);

		wp_localize_script(
			'elementor-ai',
			'ElementorAiConfig',
			[
				'is_get_started' => User::get_introduction_meta( 'ai_get_started' ),
				'connect_url' => $this->get_ai_connect_url(),
			]
		);

		wp_set_script_translations( 'elementor-ai', 'elementor' );
	}

	private function enqueue_layout_script() {
		wp_enqueue_script(
			'elementor-ai-layout',
			$this->get_js_assets_url( 'ai-layout' ),
			[
				'react',
				'react-dom',
				'backbone-marionette',
				'elementor-common',
				'elementor-web-cli',
				'elementor-editor-modules',
				'elementor-ai',
				'elementor-v2-ui',
				'elementor-v2-icons',
			],
			ELEMENTOR_VERSION,
			true
		);

		wp_set_script_translations( 'elementor-ai-layout', 'elementor' );
	}

	private function is_layout_active() {
		return Plugin::$instance->experiments->is_feature_active( self::LAYOUT_EXPERIMENT );
	}

	private function remove_temporary_containers( $data ) {
		if ( empty( $data['elements'] ) ) {
			return $data;
		}

		// If for some reason the document has been saved during an AI Layout session,
		// ensure that the temporary containers are removed from the data.
		$data['elements'] = array_filter( $data['elements'], function( $element ) {
			$is_preview_container = strpos( $element['id'], 'e-ai-preview-container' ) === 0;
			$is_screenshot_container = strpos( $element['id'], 'e-ai-screenshot-container' ) === 0;

			return ! $is_preview_container && ! $is_screenshot_container;
		} );

		return $data;
	}

	private function get_ai_connect_url() {
		$app = $this->get_ai_app();

		return $app->get_admin_url( 'authorize', [
			'utm_source' => 'ai-popup',
			'utm_campaign' => 'connect-account',
			'utm_medium' => 'wp-dash',
			'source' => 'generic',
		] );
	}

	public function ajax_ai_get_user_information( $data ) {
		$app = $this->get_ai_app();

		if ( ! $app->is_connected() ) {
			return [
				'is_connected' => false,
				'connect_url' => $this->get_ai_connect_url(),
			];
		}

		$user_usage = wp_parse_args( $app->get_usage(), [
			'hasAiSubscription' => false,
			'usedQuota' => 0,
			'quota' => 100,
		] );

		return [
			'is_connected' => true,
			'is_get_started' => User::get_introduction_meta( 'ai_get_started' ),
			'usage' => $user_usage,
		];
	}

	private function verify_permissions( $editor_post_id ) {
		$document = Plugin::$instance->documents->get( $editor_post_id );

		if ( ! $document ) {
			throw new \Exception( 'Document not found' );
		}

		if ( ! $document->is_built_with_elementor() || ! $document->is_editable_by_current_user() ) {
			throw new \Exception( 'Access denied' );
		}
	}

	public function ajax_ai_get_image_prompt_enhancer( $data ) {
		$this->verify_permissions( $data['editor_post_id'] );

		$app = $this->get_ai_app();

		if ( empty( $data['prompt'] ) ) {
			throw new \Exception( 'Missing prompt' );
		}

		if ( ! $app->is_connected() ) {
			throw new \Exception( 'not_connected' );
		}

		$result = $app->get_image_prompt_enhanced( $data['prompt'] );
		if ( is_wp_error( $result ) ) {
			throw new \Exception( $result->get_error_message() );
		}

		return [
			'text' => $result['text'],
			'response_id' => $result['responseId'],
			'usage' => $result['usage'],
		];
	}

	public function ajax_ai_get_completion_text( $data ) {
		$this->verify_permissions( $data['editor_post_id'] );

		$app = $this->get_ai_app();

		if ( empty( $data['prompt'] ) ) {
			throw new \Exception( 'Missing prompt' );
		}

		if ( ! $app->is_connected() ) {
			throw new \Exception( 'not_connected' );
		}

		$context = $this->get_request_context( $data );

		$result = $app->get_completion_text( $data['prompt'], $context );
		if ( is_wp_error( $result ) ) {
			throw new \Exception( $result->get_error_message() );
		}

		return [
			'text' => $result['text'],
			'response_id' => $result['responseId'],
			'usage' => $result['usage'],
		];
	}

	private function get_ai_app() : Ai {
		return Plugin::$instance->common->get_component( 'connect' )->get_app( 'ai' );
	}

	private function get_request_context( $data ) {
		if ( empty( $data['context'] ) ) {
			return [];
		}

		return $data['context'];
	}

	public function ajax_ai_get_edit_text( $data ) {
		$this->verify_permissions( $data['editor_post_id'] );

		$app = $this->get_ai_app();

		if ( empty( $data['input'] ) ) {
			throw new \Exception( 'Missing input' );
		}

		if ( empty( $data['instruction'] ) ) {
			throw new \Exception( 'Missing instruction' );
		}

		if ( ! $app->is_connected() ) {
			throw new \Exception( 'not_connected' );
		}

		$context = $this->get_request_context( $data );

		$result = $app->get_edit_text( $data['input'], $data['instruction'], $context );
		if ( is_wp_error( $result ) ) {
			throw new \Exception( $result->get_error_message() );
		}

		return [
			'text' => $result['text'],
			'response_id' => $result['responseId'],
			'usage' => $result['usage'],
		];
	}

	public function ajax_ai_get_custom_code( $data ) {
		$app = $this->get_ai_app();

		if ( empty( $data['prompt'] ) ) {
			throw new \Exception( 'Missing prompt' );
		}

		if ( empty( $data['language'] ) ) {
			throw new \Exception( 'Missing language' );
		}

		if ( ! $app->is_connected() ) {
			throw new \Exception( 'not_connected' );
		}

		$context = $this->get_request_context( $data );

		$result = $app->get_custom_code( $data['prompt'], $data['language'], $context );
		if ( is_wp_error( $result ) ) {
			throw new \Exception( $result->get_error_message() );
		}

		return [
			'text' => $result['text'],
			'response_id' => $result['responseId'],
			'usage' => $result['usage'],
		];
	}

	public function ajax_ai_get_custom_css( $data ) {
		$this->verify_permissions( $data['editor_post_id'] );

		$app = $this->get_ai_app();

		if ( empty( $data['prompt'] ) ) {
			throw new \Exception( 'Missing prompt' );
		}

		if ( empty( $data['html_markup'] ) ) {
			$data['html_markup'] = '';
		}

		if ( empty( $data['element_id'] ) ) {
			throw new \Exception( 'Missing element_id' );
		}

		if ( ! $app->is_connected() ) {
			throw new \Exception( 'not_connected' );
		}

		$context = $this->get_request_context( $data );

		$result = $app->get_custom_css( $data['prompt'], $data['html_markup'], $data['element_id'], $context );
		if ( is_wp_error( $result ) ) {
			throw new \Exception( $result->get_error_message() );
		}

		return [
			'text' => $result['text'],
			'response_id' => $result['responseId'],
			'usage' => $result['usage'],
		];
	}

	public function ajax_ai_set_get_started( $data ) {
		$app = $this->get_ai_app();

		User::set_introduction_viewed( [
			'introductionKey' => 'ai_get_started',
		] );

		return $app->set_get_started();
	}

	public function ajax_ai_set_status_feedback( $data ) {
		if ( empty( $data['response_id'] ) ) {
			throw new \Exception( 'Missing response_id' );
		}

		$app = $this->get_ai_app();

		if ( ! $app->is_connected() ) {
			throw new \Exception( 'not_connected' );
		}

		$app->set_status_feedback( $data['response_id'] );

		return [];
	}

	public function ajax_ai_get_text_to_image( $data ) {
		$this->verify_permissions( $data['editor_post_id'] );

		if ( empty( $data['prompt'] ) ) {
			throw new \Exception( 'Missing prompt' );
		}

		$app = $this->get_ai_app();

		if ( ! $app->is_connected() ) {
			throw new \Exception( 'not_connected' );
		}

		$context = $this->get_request_context( $data );

		$result = $app->get_text_to_image( $data['prompt'], $data['promptSettings'], $context );

		if ( is_wp_error( $result ) ) {
			throw new \Exception( $result->get_error_message() );
		}

		return [
			'images' => $result['images'],
			'response_id' => $result['responseId'],
			'usage' => $result['usage'],
		];
	}

	public function ajax_ai_get_image_to_image( $data ) {
		$this->verify_permissions( $data['editor_post_id'] );

		$app = $this->get_ai_app();

		if ( empty( $data['prompt'] ) ) {
			throw new \Exception( 'Missing prompt' );
		}

		if ( empty( $data['image'] ) || empty( $data['image']['id'] ) ) {
			throw new \Exception( 'Missing Image' );
		}

		if ( empty( $data['promptSettings'] ) ) {
			throw new \Exception( 'Missing prompt settings' );
		}

		if ( ! $app->is_connected() ) {
			throw new \Exception( 'not_connected' );
		}

		$context = $this->get_request_context( $data );

		$result = $app->get_image_to_image( [
			'prompt' => $data['prompt'],
			'promptSettings' => $data['promptSettings'],
			'attachment_id' => $data['image']['id'],
		], $context );

		if ( is_wp_error( $result ) ) {
			throw new \Exception( $result->get_error_message() );
		}

		return [
			'images' => $result['images'],
			'response_id' => $result['responseId'],
			'usage' => $result['usage'],
		];
	}

	public function ajax_ai_get_image_to_image_upscale( $data ) {
		$this->verify_permissions( $data['editor_post_id'] );

		$app = $this->get_ai_app();

		if ( empty( $data['image'] ) || empty( $data['image']['id'] ) ) {
			throw new \Exception( 'Missing Image' );
		}

		if ( empty( $data['promptSettings'] ) ) {
			throw new \Exception( 'Missing prompt settings' );
		}

		if ( ! $app->is_connected() ) {
			throw new \Exception( 'not_connected' );
		}

		$context = $this->get_request_context( $data );

		$result = $app->get_image_to_image_upscale( [
			'promptSettings' => $data['promptSettings'],
			'attachment_id' => $data['image']['id'],
		], $context );

		if ( is_wp_error( $result ) ) {
			throw new \Exception( $result->get_error_message() );
		}

		return [
			'images' => $result['images'],
			'response_id' => $result['responseId'],
			'usage' => $result['usage'],
		];
	}

	public function ajax_ai_get_image_to_image_replace_background( $data ) {
		$this->verify_permissions( $data['editor_post_id'] );

		$app = $this->get_ai_app();

		if ( empty( $data['image'] ) || empty( $data['image']['id'] ) ) {
			throw new \Exception( 'Missing Image' );
		}

		if ( empty( $data['prompt'] ) ) {
			throw new \Exception( 'Prompt Missing' );
		}

		if ( ! $app->is_connected() ) {
			throw new \Exception( 'not_connected' );
		}

		$context = $this->get_request_context( $data );

		$result = $app->get_image_to_image_replace_background( [
			'attachment_id' => $data['image']['id'],
			'prompt' => $data['prompt'],
		], $context );

		if ( is_wp_error( $result ) ) {
			throw new \Exception( $result->get_error_message() );
		}

		return [
			'images' => $result['images'],
			'response_id' => $result['responseId'],
			'usage' => $result['usage'],
		];
	}

	public function ajax_ai_get_image_to_image_remove_background( $data ) {
		$this->verify_permissions( $data['editor_post_id'] );

		$app = $this->get_ai_app();

		if ( empty( $data['image'] ) || empty( $data['image']['id'] ) ) {
			throw new \Exception( 'Missing Image' );
		}

		if ( ! $app->is_connected() ) {
			throw new \Exception( 'not_connected' );
		}

		$context = $this->get_request_context( $data );

		$result = $app->get_image_to_image_remove_background( [
			'attachment_id' => $data['image']['id'],
		], $context );

		if ( is_wp_error( $result ) ) {
			throw new \Exception( $result->get_error_message() );
		}

		return [
			'images' => $result['images'],
			'response_id' => $result['responseId'],
			'usage' => $result['usage'],
		];
	}

	public function ajax_ai_get_image_to_image_mask( $data ) {
		$this->verify_permissions( $data['editor_post_id'] );

		$app = $this->get_ai_app();

		if ( empty( $data['prompt'] ) ) {
			throw new \Exception( 'Missing prompt' );
		}

		if ( empty( $data['image'] ) || empty( $data['image']['id'] ) ) {
			throw new \Exception( 'Missing Image' );
		}

		if ( empty( $data['promptSettings'] ) ) {
			throw new \Exception( 'Missing prompt settings' );
		}

		if ( ! $app->is_connected() ) {
			throw new \Exception( 'not_connected' );
		}

		if ( empty( $data['mask'] ) ) {
			throw new \Exception( 'Missing Mask' );
		}

		$context = $this->get_request_context( $data );

		$result = $app->get_image_to_image_mask( [
			'prompt' => $data['prompt'],
			'promptSettings' => $data['promptSettings'],
			'attachment_id' => $data['image']['id'],
			'mask' => $data['mask'],
		], $context );

		if ( is_wp_error( $result ) ) {
			throw new \Exception( $result->get_error_message() );
		}

		return [
			'images' => $result['images'],
			'response_id' => $result['responseId'],
			'usage' => $result['usage'],
		];
	}
	public function ajax_ai_get_image_to_image_outpainting( $data ) {
		$this->verify_permissions( $data['editor_post_id'] );

		$app = $this->get_ai_app();

		if ( empty( $data['prompt'] ) ) {
			throw new \Exception( 'Missing prompt' );
		}

		if ( ! $app->is_connected() ) {
			throw new \Exception( 'not_connected' );
		}

		if ( empty( $data['mask'] ) ) {
			throw new \Exception( 'Missing Expended Image' );
		}

		$context = $this->get_request_context( $data );

		$result = $app->get_image_to_image_out_painting( [
			'prompt' => $data['prompt'],
			'mask' => $data['mask'],
		], $context );

		if ( is_wp_error( $result ) ) {
			throw new \Exception( $result->get_error_message() );
		}

		return [
			'images' => $result['images'],
			'response_id' => $result['responseId'],
			'usage' => $result['usage'],
		];
	}

	public function ajax_ai_upload_image( $data ) {
		if ( empty( $data['image'] ) ) {
			throw new \Exception( 'Missing image data' );
		}

		$image = $data['image'];

		if ( empty( $image['image_url'] ) ) {
			throw new \Exception( 'Missing image_url' );
		}

		$image_data = $this->upload_image( $image['image_url'], $data['prompt'], $data['editor_post_id'] );

		if ( is_wp_error( $image_data ) ) {
			throw new \Exception( $image_data->get_error_message() );
		}

		if ( ! empty( $image['use_gallery_image'] ) && ! empty( $image['id'] ) ) {
			 $app = $this->get_ai_app();
			 $app->set_used_gallery_image( $image['id'] );
		}

		return [
			'image' => array_merge( $image_data, $data ),
		];
	}

	public function ajax_ai_generate_layout( $data ) {
		$this->verify_permissions( $data['editor_post_id'] );

		$app = $this->get_ai_app();

		if ( empty( $data['prompt'] ) ) {
			throw new \Exception( 'Missing prompt' );
		}

		if ( ! $app->is_connected() ) {
			throw new \Exception( 'not_connected' );
		}

		$result = $app->generate_layout(
			$data['prompt'],
			$this->prepare_generate_layout_context(),
			$data['variationType']
		);

		if ( is_wp_error( $result ) ) {
			throw new \Exception( $result->get_error_message() );
		}

		$template = $result['text']['elements'][0] ?? null;

		if ( empty( $template ) || ! is_array( $template ) ) {
			throw new \Exception( 'unknown_error' );
		}

		return [
			'all' => [],
			'text' => $template,
			'response_id' => $result['responseId'],
			'usage' => $result['usage'],
		];
	}

	public function ajax_ai_get_layout_prompt_enhancer( $data ) {
		$this->verify_permissions( $data['editor_post_id'] );

		$app = $this->get_ai_app();

		if ( empty( $data['prompt'] ) ) {
			throw new \Exception( 'Missing prompt' );
		}

		if ( ! $app->is_connected() ) {
			throw new \Exception( 'not_connected' );
		}

		$result = $app->get_layout_prompt_enhanced( $data['prompt'] );

		if ( is_wp_error( $result ) ) {
			throw new \Exception( $result->get_error_message() );
		}

		return [
			'text' => $result['text'] ?? $data['prompt'],
			'response_id' => $result['responseId'] ?? '',
			'usage' => $result['usage'] ?? '',
		];
	}

	private function prepare_generate_layout_context() {
		$kit = Plugin::$instance->kits_manager->get_active_kit();

		if ( ! $kit ) {
			return [];
		}

		$kits_data = Collection::make( $kit->get_data()['settings'] ?? [] );

		$colors = $kits_data
			->filter( function ( $_, $key ) {
				return in_array( $key, [ 'system_colors', 'custom_colors' ], true );
			} )
			->flatten()
			->filter( function ( $val ) {
				return ! empty( $val['_id'] );
			} )
			->map( function ( $val ) {
				return [
					'id' => $val['_id'],
					'label' => $val['title'] ?? null,
					'value' => $val['color'] ?? null,
				];
			} );

		$typography = $kits_data
			->filter( function ( $_, $key ) {
				return in_array( $key, [ 'system_typography', 'custom_typography' ], true );
			} )
			->flatten()
			->filter( function ( $val ) {
				return ! empty( $val['_id'] );
			} )
			->map( function ( $val ) {
				$font_size = null;

				if ( isset(
					$val['typography_font_size']['unit'],
					$val['typography_font_size']['size']
				) ) {
					$prop = $val['typography_font_size'];

					$font_size = 'custom' === $prop['unit']
						? $prop['size']
						: $prop['size'] . $prop['unit'];
				}

				return [
					'id' => $val['_id'],
					'label' => $val['title'] ?? null,
					'value' => [
						'family' => $val['typography_font_family'] ?? null,
						'weight' => $val['typography_font_weight'] ?? null,
						'style' => $val['typography_font_style'] ?? null,
						'size' => $font_size,
					],
				];
			} );

		return [
			'globals' => [
				'colors' => $colors->all(),
				'typography' => $typography->all(),
			],
		];
	}

	private function upload_image( $image_url, $image_title, $parent_post_id = 0 ) {
		if ( ! current_user_can( 'upload_files' ) ) {
			throw new \Exception( 'Not Allowed to Upload images' );
		}

		$attachment_id = media_sideload_image( $image_url, $parent_post_id, $image_title, 'id' );

		if ( ! empty( $attachment_id['error'] ) ) {
			return new \WP_Error( 'upload_error', $attachment_id['error'] );
		}

		return [
			'id' => $attachment_id,
			'url' => wp_get_attachment_image_url( $attachment_id, 'full' ),
			'alt' => $image_title,
			'source' => 'library',
		];
	}
}
