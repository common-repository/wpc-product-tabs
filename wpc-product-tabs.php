<?php
/*
Plugin Name: WPC Product Tabs for WooCommerce
Plugin URI: https://wpclever.net/
Description: Product tabs manager for WooCommerce.
Version: 4.1.4
Author: WPClever
Author URI: https://wpclever.net
Text Domain: wpc-product-tabs
Domain Path: /languages/
Requires Plugins: woocommerce
Requires at least: 4.0
Tested up to: 6.6
WC requires at least: 3.0
WC tested up to: 9.3
*/

defined( 'ABSPATH' ) || exit;

! defined( 'WOOST_VERSION' ) && define( 'WOOST_VERSION', '4.1.4' );
! defined( 'WOOST_LITE' ) && define( 'WOOST_LITE', __FILE__ );
! defined( 'WOOST_FILE' ) && define( 'WOOST_FILE', __FILE__ );
! defined( 'WOOST_URI' ) && define( 'WOOST_URI', plugin_dir_url( __FILE__ ) );
! defined( 'WOOST_DIR' ) && define( 'WOOST_DIR', plugin_dir_path( __FILE__ ) );
! defined( 'WOOST_SUPPORT' ) && define( 'WOOST_SUPPORT', 'https://wpclever.net/support?utm_source=support&utm_medium=woost&utm_campaign=wporg' );
! defined( 'WOOST_REVIEWS' ) && define( 'WOOST_REVIEWS', 'https://wordpress.org/support/plugin/wpc-product-tabs/reviews/?filter=5' );
! defined( 'WOOST_CHANGELOG' ) && define( 'WOOST_CHANGELOG', 'https://wordpress.org/plugins/wpc-product-tabs/#developers' );
! defined( 'WOOST_DISCUSSION' ) && define( 'WOOST_DISCUSSION', 'https://wordpress.org/support/plugin/wpc-product-tabs' );
! defined( 'WPC_URI' ) && define( 'WPC_URI', WOOST_URI );

include 'includes/dashboard/wpc-dashboard.php';
include 'includes/kit/wpc-kit.php';
include 'includes/hpos.php';

if ( ! function_exists( 'woost_init' ) ) {
	add_action( 'plugins_loaded', 'woost_init', 11 );

	function woost_init() {
		// load text-domain
		load_plugin_textdomain( 'wpc-product-tabs', false, basename( __DIR__ ) . '/languages/' );

		if ( ! function_exists( 'WC' ) || ! version_compare( WC()->version, '3.0', '>=' ) ) {
			add_action( 'admin_notices', 'woost_notice_wc' );

			return null;
		}

		if ( ! class_exists( 'WPCleverWoost' ) && class_exists( 'WC_Product' ) ) {
			class WPCleverWoost {
				protected static $instance = null;

				public static function instance() {
					if ( is_null( self::$instance ) ) {
						self::$instance = new self();
					}

					return self::$instance;
				}

				function __construct() {
					// init
					add_action( 'init', [ $this, 'init' ] );

					// enqueue
					add_action( 'admin_enqueue_scripts', [ $this, 'admin_enqueue_scripts' ] );

					// settings page
					add_action( 'admin_init', [ $this, 'register_settings' ] );
					add_action( 'admin_menu', [ $this, 'admin_menu' ] );

					// settings link
					add_filter( 'plugin_action_links', [ $this, 'action_links' ], 10, 2 );
					add_filter( 'plugin_row_meta', [ $this, 'row_meta' ], 10, 2 );

					// add tab
					// run before woocommerce_sort_product_tabs:99
					add_filter( 'woocommerce_product_tabs', [ $this, 'product_tabs' ], 98 );
					add_filter( 'woocommerce_product_additional_information_heading', [
						$this,
						'information_heading'
					], 98 );
					add_filter( 'woocommerce_product_description_heading', [ $this, 'description_heading' ], 98 );

					// ajax
					add_action( 'wp_ajax_woost_add_tab', [ $this, 'ajax_add_tab' ] );
					add_action( 'wp_ajax_woost_search_term', [ $this, 'ajax_search_term' ] );
					add_action( 'wp_ajax_woost_search_product', [ $this, 'ajax_search_product' ] );

					// product data
					add_filter( 'woocommerce_product_data_tabs', [ $this, 'product_data_tabs' ] );
					add_action( 'woocommerce_product_data_panels', [ $this, 'product_data_panels' ] );

					// export
					add_filter( 'woocommerce_product_export_meta_value', [ $this, 'export_process' ], 10, 3 );

					// import
					add_filter( 'woocommerce_product_import_pre_insert_product_object', [
						$this,
						'import_process'
					], 10, 2 );
				}

				function init() {
					add_shortcode( 'woost_tab_content', [ $this, 'shortcode_tab_content' ] );
					add_shortcode( 'woost_product_tab_content', [ $this, 'shortcode_product_tab_content' ] );
				}

				function shortcode_tab_content( $attrs ) {
					$output = '';

					$attrs = shortcode_atts( [
						'product_id' => 0,
						'tab'        => ''
					], $attrs, 'woost_tab_content' );

					if ( ! empty( $attrs['tab'] ) ) {
						$key = $attrs['tab'];

						if ( ! empty( $attrs['product_id'] ) ) {
							$tabs = get_post_meta( absint( $attrs['product_id'] ), 'woost_tabs', true ) ?: [];
						} else {
							$tabs = get_option( 'woost_tabs', [] );
						}

						if ( ! empty( $tabs[ $key ]['content'] ) ) {
							// ignore woost shortcodes
							$tab_content = str_replace( '[woost_', '[woost_ignored_', $tabs[ $key ]['content'] );
							$output      = do_shortcode( $tab_content );
						}
					}

					return apply_filters( 'woost_tab_content_shortcode', $output, $attrs );
				}

				function shortcode_product_tab_content( $attrs ) {
					global $product;
					$output = '';

					$attrs = shortcode_atts( [
						'tab'       => '',
						'tab_title' => ''
					], $attrs, 'woost_product_tab_content' );

					if ( $product ) {
						$tabs        = get_post_meta( absint( $product->get_id() ), 'woost_tabs', true ) ?: [];
						$tab_content = '';

						if ( ! empty( $tabs ) ) {
							if ( ! empty( $attrs['tab'] ) ) {
								if ( is_numeric( $attrs['tab'] ) ) {
									// get tab by index
									$index = absint( $attrs['tab'] ) - 1;
									$keys  = array_keys( $tabs );

									if ( ! empty( $keys[ $index ] ) ) {
										$key = $keys[ $index ];

										if ( ! empty( $tabs[ $key ]['content'] ) ) {
											$tab_content = $tabs[ $key ]['content'];
										}
									}
								} else {
									// get tab by key
									$key = $attrs['tab'];

									if ( ! empty( $tabs[ $key ]['content'] ) ) {
										$tab_content = $tabs[ $key ]['content'];
									}
								}
							} elseif ( ! empty( $attrs['tab_title'] ) ) {
								foreach ( $tabs as $tab ) {
									if ( trim( $attrs['tab_title'] ) === trim( $tab['title'] ) ) {
										$tab_content = $tab['content'];
										break;
									}
								}
							}
						}

						// ignore woost shortcodes
						$tab_content = str_replace( '[woost_', '[woost_ignored_', $tab_content );
						$output      = do_shortcode( $tab_content );
					}

					return apply_filters( 'woost_product_tab_content_shortcode', $output, $attrs );
				}

				function admin_enqueue_scripts( $hook ) {
					if ( apply_filters( 'woost_ignore_backend_scripts', false, $hook ) ) {
						return null;
					}

					wp_enqueue_style( 'woost-backend', WOOST_URI . 'assets/css/backend.css', [ 'woocommerce_admin_styles' ], WOOST_VERSION );
					wp_enqueue_script( 'woost-backend', WOOST_URI . 'assets/js/backend.js', [
						'jquery',
						'jquery-ui-sortable',
						'selectWoo'
					], WOOST_VERSION, true );
				}

				function action_links( $links, $file ) {
					static $plugin;

					if ( ! isset( $plugin ) ) {
						$plugin = plugin_basename( __FILE__ );
					}

					if ( $plugin === $file ) {
						$global               = '<a href="' . esc_url( admin_url( 'admin.php?page=wpclever-woost&tab=global' ) ) . '">' . esc_html__( 'Global Tabs', 'wpc-product-tabs' ) . '</a>';
						$links['wpc-premium'] = '<a href="' . esc_url( admin_url( 'admin.php?page=wpclever-woost&tab=premium' ) ) . '">' . esc_html__( 'Premium Version', 'wpc-product-tabs' ) . '</a>';
						array_unshift( $links, $global );
					}

					return (array) $links;
				}

				function row_meta( $links, $file ) {
					static $plugin;

					if ( ! isset( $plugin ) ) {
						$plugin = plugin_basename( __FILE__ );
					}

					if ( $plugin === $file ) {
						$row_meta = [
							'support' => '<a href="' . esc_url( WOOST_DISCUSSION ) . '" target="_blank">' . esc_html__( 'Community support', 'wpc-product-tabs' ) . '</a>',
						];

						return array_merge( $links, $row_meta );
					}

					return (array) $links;
				}

				function register_settings() {
					// settings
					register_setting( 'woost_settings', 'woost_tabs' );
				}

				function admin_menu() {
					add_submenu_page( 'wpclever', esc_html__( 'WPC Product Tabs', 'wpc-product-tabs' ), esc_html__( 'Product Tabs', 'wpc-product-tabs' ), 'manage_options', 'wpclever-woost', [
						$this,
						'admin_menu_content'
					] );
				}

				function admin_menu_content() {
					$active_tab = sanitize_key( $_GET['tab'] ?? 'global' );
					?>
                    <div class="wpclever_settings_page wrap">
                        <h1 class="wpclever_settings_page_title"><?php echo esc_html__( 'WPC Product Tabs', 'wpc-product-tabs' ) . ' ' . esc_html( WOOST_VERSION ) . ' ' . ( defined( 'WOOST_PREMIUM' ) ? '<span class="premium" style="display: none">' . esc_html__( 'Premium', 'wpc-product-tabs' ) . '</span>' : '' ); ?></h1>
                        <div class="wpclever_settings_page_desc about-text">
                            <p>
								<?php printf( /* translators: stars */ esc_html__( 'Thank you for using our plugin! If you are satisfied, please reward it a full five-star %s rating.', 'wpc-product-tabs' ), '<span style="color:#ffb900">&#9733;&#9733;&#9733;&#9733;&#9733;</span>' ); ?>
                                <br/>
                                <a href="<?php echo esc_url( WOOST_REVIEWS ); ?>" target="_blank"><?php esc_html_e( 'Reviews', 'wpc-product-tabs' ); ?></a> |
                                <a href="<?php echo esc_url( WOOST_CHANGELOG ); ?>" target="_blank"><?php esc_html_e( 'Changelog', 'wpc-product-tabs' ); ?></a> |
                                <a href="<?php echo esc_url( WOOST_DISCUSSION ); ?>" target="_blank"><?php esc_html_e( 'Discussion', 'wpc-product-tabs' ); ?></a>
                            </p>
                        </div>
						<?php if ( isset( $_GET['settings-updated'] ) && $_GET['settings-updated'] ) { ?>
                            <div class="notice notice-success is-dismissible">
                                <p><?php esc_html_e( 'Settings updated.', 'wpc-product-tabs' ); ?></p>
                            </div>
						<?php } ?>
                        <div class="wpclever_settings_page_nav">
                            <h2 class="nav-tab-wrapper">
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wpclever-woost&tab=global' ) ); ?>" class="<?php echo esc_attr( $active_tab === 'global' ? 'nav-tab nav-tab-active' : 'nav-tab' ); ?>">
									<?php esc_html_e( 'Global Tabs', 'wpc-product-tabs' ); ?>
                                </a>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wpclever-woost&tab=premium' ) ); ?>" class="<?php echo esc_attr( $active_tab === 'premium' ? 'nav-tab nav-tab-active' : 'nav-tab' ); ?>" style="color: #c9356e">
									<?php esc_html_e( 'Premium Version', 'wpc-product-tabs' ); ?>
                                </a>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wpclever-kit' ) ); ?>" class="nav-tab">
									<?php esc_html_e( 'Essential Kit', 'wpc-product-tabs' ); ?>
                                </a>
                            </h2>
                        </div>
                        <div class="wpclever_settings_page_content">
							<?php if ( 'global' === $active_tab ) {
								wp_enqueue_editor();
								$saved_tabs = get_option( 'woost_tabs' );

								if ( empty( $saved_tabs ) ) {
									$saved_tabs = [
										[
											'type'  => 'description',
											'title' => esc_html__( 'Description', 'wpc-product-tabs' ),
										],
										[
											'type'  => 'additional_information',
											'title' => esc_html__( 'Additional Information', 'wpc-product-tabs' ),
										],
										[
											'type'  => 'reviews',
											'title' => /* translators: reviews */
												esc_html__( 'Reviews (%d)', 'wpc-product-tabs' ),
										]
									];
								}
								?>
                                <form method="post" action="options.php">
                                    <table class="form-table">
                                        <tr>
                                            <td colspan="2" class="woost-tabs-wrapper">
                                                <div class="woost-tabs">
													<?php if ( is_array( $saved_tabs ) && ( count( $saved_tabs ) > 0 ) ) {
														foreach ( $saved_tabs as $saved_tab ) {
															self::tab( $saved_tab );
														}
													} ?>
                                                </div>
												<?php self::new_tab(); ?>
                                            </td>
                                        </tr>
                                        <tr class="submit">
                                            <th colspan="2">
												<?php settings_fields( 'woost_settings' ); ?><?php submit_button(); ?>
                                            </th>
                                        </tr>
                                    </table>
                                </form>
							<?php } elseif ( $active_tab === 'premium' ) { ?>
                                <div class="wpclever_settings_page_content_text">
                                    <p>
                                        Get the Premium Version just $29!
                                        <a href="https://wpclever.net/downloads/product-tabs?utm_source=pro&utm_medium=woost&utm_campaign=wporg" target="_blank">https://wpclever.net/downloads/product-tabs</a>
                                    </p>
                                    <p><strong>Extra features for Premium Version:</strong></p>
                                    <ul style="margin-bottom: 0">
                                        <li>- Manage tabs at product basis.</li>
                                        <li>- Use dynamic content tab.</li>
                                        <li>- Use "Selected products" for global tabs.</li>
                                        <li>- Get the lifetime update & premium support.</li>
                                    </ul>
                                </div>
							<?php } ?>
                        </div><!-- /.wpclever_settings_page_content -->
                        <div class="wpclever_settings_page_suggestion">
                            <div class="wpclever_settings_page_suggestion_label">
                                <span class="dashicons dashicons-yes-alt"></span> Suggestion
                            </div>
                            <div class="wpclever_settings_page_suggestion_content">
                                <div>
                                    To display custom engaging real-time messages on any wished positions, please install
                                    <a href="https://wordpress.org/plugins/wpc-smart-messages/" target="_blank">WPC Smart Messages</a> plugin. It's free!
                                </div>
                                <div>
                                    Wanna save your precious time working on variations? Try our brand-new free plugin
                                    <a href="https://wordpress.org/plugins/wpc-variation-bulk-editor/" target="_blank">WPC Variation Bulk Editor</a> and
                                    <a href="https://wordpress.org/plugins/wpc-variation-duplicator/" target="_blank">WPC Variation Duplicator</a>.
                                </div>
                            </div>
                        </div>
                    </div>
					<?php
				}

				function ajax_add_tab() {
					self::tab( [
						'type'    => 'description',
						'title'   => esc_html__( 'Tab title', 'wpc-product-tabs' ),
						'content' => ''
					], ( ! empty( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0 ), true );

					wp_die();
				}

				function ajax_search_term() {
					$return = [];

					$args = [
						'taxonomy'   => sanitize_text_field( $_REQUEST['taxonomy'] ),
						'orderby'    => 'id',
						'order'      => 'ASC',
						'hide_empty' => false,
						'fields'     => 'all',
						'name__like' => sanitize_text_field( $_REQUEST['q'] ),
					];

					$terms = get_terms( $args );

					if ( count( $terms ) ) {
						foreach ( $terms as $term ) {
							$return[] = [ $term->slug, $term->name ];
						}
					}

					wp_send_json( $return );
				}

				function ajax_search_product() {
					if ( isset( $_REQUEST['term'] ) ) {
						$term = (string) wc_clean( wp_unslash( $_REQUEST['term'] ) );
					}

					if ( empty( $term ) ) {
						wp_die();
					}

					$products   = [];
					$limit      = absint( apply_filters( 'woost_json_search_limit', 30 ) );
					$data_store = WC_Data_Store::load( 'product' );
					$ids        = $data_store->search_products( $term, '', true, false, $limit );

					foreach ( $ids as $id ) {
						$product_object = wc_get_product( $id );

						if ( ! wc_products_array_filter_readable( $product_object ) ) {
							continue;
						}

						$formatted_name = $product_object->get_formatted_name();

						if ( apply_filters( 'woost_use_sku', false ) ) {
							$products[] = [
								$product_object->get_sku() ?: $product_object->get_id(),
								rawurldecode( wp_strip_all_tags( $formatted_name ) )
							];
						} else {
							$products[] = [
								$product_object->get_id(),
								rawurldecode( wp_strip_all_tags( $formatted_name ) )
							];
						}
					}

					wp_send_json( apply_filters( 'woost_json_search_found_products', $products ) );
				}

				function tab( $tab, $product_id = 0, $new = false ) {
					$tab = array_merge( [
						'key'       => '',
						'type'      => 'custom',
						'apply'     => 'all',
						'apply_val' => [],
						'products'  => [],
						'roles'     => [ 'woost_all' ],
						'title'     => '',
						'content'   => ''
					], $tab );

					if ( ! empty( $tab['key'] ) ) {
						$key = $tab['key'];
					} else {
						$key = self::generate_key();
					}
					?>
                    <div class="<?php echo esc_attr( 'woost-tab woost-tab-' . $tab['type'] ); ?> <?php echo esc_attr( $new ? 'active' : '' ); ?>">
                        <div class="woost-tab-header">
                            <span class="woost-tab-move"><?php esc_html_e( 'move', 'wpc-product-tabs' ); ?></span>
                            <span class="woost-tab-label"><span class="woost-tab-title"><?php echo esc_html( $tab['title'] ); ?></span> <span class="woost-tab-label-type">#<?php echo esc_attr( $tab['type'] ); ?></span> <span class="woost-tab-label-apply"><?php echo esc_attr( $tab['apply'] ); ?></span></span>
                            <span class="woost-tab-remove"><?php esc_html_e( 'remove', 'wpc-product-tabs' ); ?></span>
                        </div>
                        <div class="woost-tab-content">
                            <div class="woost-tab-line woost-tab-line-apply">
                                <div class="woost-tab-line-label">
									<?php esc_html_e( 'Apply for', 'wpc-product-tabs' ); ?>
                                </div>
                                <div class="woost-tab-line-value">
                                    <div class="woost_apply_wrapper">
                                        <label>
                                            <select class="woost_apply" name="woost_tabs[<?php echo esc_attr( $key ); ?>][apply]">
                                                <option value="none" <?php selected( $tab['apply'], 'none' ); ?>><?php esc_attr_e( 'None (Disable)', 'wpc-product-tabs' ); ?></option>
                                                <option value="all" <?php selected( $tab['apply'], 'all' ); ?>><?php esc_attr_e( 'All products', 'wpc-product-tabs' ); ?></option>
                                                <option value="products" <?php selected( $tab['apply'], 'products' ); ?> disabled><?php esc_attr_e( 'Selected products', 'wpc-product-tabs' ); ?></option>
												<?php
												$taxonomies = get_object_taxonomies( 'product', 'objects' );

												foreach ( $taxonomies as $taxonomy ) {
													echo '<option value="' . esc_attr( $taxonomy->name ) . '" ' . selected( $tab['apply'], $taxonomy->name, false ) . '>' . esc_html( $taxonomy->label ) . '</option>';
												}
												?>
                                            </select> </label>
                                    </div>
                                    <div class="woost_apply_val_wrapper woost_show hide_if_apply_none hide_if_apply_all hide_if_apply_products">
                                        <label>
                                            <select class="woost_terms" multiple="multiple" name="woost_tabs[<?php echo esc_attr( $key ); ?>][apply_val][]" data-<?php echo esc_attr( $tab['apply'] ); ?>="<?php echo esc_attr( implode( ',', (array) $tab['apply_val'] ) ); ?>">
												<?php if ( is_array( $tab['apply_val'] ) && ! empty( $tab['apply_val'] ) ) {
													foreach ( $tab['apply_val'] as $t ) {
														if ( $term = get_term_by( 'slug', $t, $tab['apply'] ) ) {
															echo '<option value="' . esc_attr( $t ) . '" selected>' . esc_html( $term->name ) . '</option>';
														}
													}
												} ?>
                                            </select> </label>
                                    </div>
                                </div>
                            </div>
                            <div class="woost-tab-line woost-tab-line-roles">
                                <div class="woost-tab-line-label">
									<?php esc_html_e( 'User roles', 'wpc-product-tabs' ); ?>
                                </div>
                                <div class="woost-tab-line-value">
                                    <label>
                                        <select name="woost_tabs[<?php echo esc_attr( $key ); ?>][roles][]" multiple class="woost_roles">
											<?php
											global $wp_roles;
											$roles = ( ! empty( $tab['roles'] ) ) ? (array) $tab['roles'] : [ 'woost_all' ];

											echo '<option value="woost_all" ' . ( in_array( 'woost_all', $roles ) ? 'selected' : '' ) . '>' . esc_html__( 'All', 'wpc-product-tabs' ) . '</option>';
											echo '<option value="woost_user" ' . ( in_array( 'woost_user', $roles ) ? 'selected' : '' ) . '>' . esc_html__( 'User (logged in)', 'wpc-product-tabs' ) . '</option>';
											echo '<option value="woost_guest" ' . ( in_array( 'woost_guest', $roles ) ? 'selected' : '' ) . '>' . esc_html__( 'Guest (not logged in)', 'wpc-product-tabs' ) . '</option>';

											foreach ( $wp_roles->roles as $role => $details ) {
												echo '<option value="' . esc_attr( $role ) . '" ' . ( in_array( $role, $roles ) ? 'selected' : '' ) . '>' . esc_html( $details['name'] ) . '</option>';
											}
											?>
                                        </select> </label>
                                </div>
                            </div>
                            <div class="woost-tab-line woost-tab-line-type">
                                <div class="woost-tab-line-label">
									<?php esc_html_e( 'Type', 'wpc-product-tabs' ); ?>
                                </div>
                                <div class="woost-tab-line-value">
                                    <label>
                                        <select class="woost_type" name="woost_tabs[<?php echo esc_attr( $key ); ?>][type]">
                                            <option value="description" <?php selected( $tab['type'], 'description' ); ?>><?php esc_html_e( 'Description', 'wpc-product-tabs' ); ?></option>
                                            <option value="additional_information" <?php selected( $tab['type'], 'additional_information' ); ?>><?php esc_html_e( 'Additional Information', 'wpc-product-tabs' ); ?></option>
                                            <option value="reviews" <?php selected( $tab['type'], 'reviews' ); ?>><?php /* translators: reviews */
												esc_html_e( 'Reviews (%d)', 'wpc-product-tabs' ); ?></option>
											<?php
											if ( class_exists( 'WPCleverWoosb' ) && ( ( get_option( '_woosb_bundled_position', 'above' ) === 'tab' ) || ( get_option( '_woosb_bundles_position', 'no' ) === 'tab' ) ) ) {
												// old version
												echo '<option value="woosb" ' . selected( $tab['type'], 'woosb', false ) . '>' . esc_html__( 'WPC Product Bundles', 'wpc-product-tabs' ) . '</option>';
											}

											if ( function_exists( 'WPCleverWoosb_Helper' ) && ( ( WPCleverWoosb_Helper()::get_setting( 'bundled_position', 'above' ) === 'tab' ) || ( WPCleverWoosb_Helper()::get_setting( 'bundles_position', 'no' ) === 'tab' ) ) ) {
												echo '<option value="woosb" ' . selected( $tab['type'], 'woosb', false ) . '>' . esc_html__( 'WPC Product Bundles', 'wpc-product-tabs' ) . '</option>';
											}

											if ( function_exists( 'WPCleverWoosb_Helper' ) && ( WPCleverWoosb_Helper()::get_setting( 'bundled_position', 'above' ) === 'tab' ) ) {
												echo '<option value="woosb_bundled" ' . selected( $tab['type'], 'woosb_bundled', false ) . '>' . esc_html__( 'WPC Product Bundles - Bundled products', 'wpc-product-tabs' ) . '</option>';
											}

											if ( function_exists( 'WPCleverWoosb_Helper' ) && ( WPCleverWoosb_Helper()::get_setting( 'bundles_position', 'no' ) === 'tab' ) ) {
												echo '<option value="woosb_bundles" ' . selected( $tab['type'], 'woosb_bundles', false ) . '>' . esc_html__( 'WPC Product Bundles - Bundles products', 'wpc-product-tabs' ) . '</option>';
											}

											if ( class_exists( 'WPCleverWoosg' ) && ( get_option( '_woosg_position', 'above' ) === 'tab' ) ) {
												// old version
												echo '<option value="woosg" ' . selected( $tab['type'], 'woosg', false ) . '>' . esc_html__( 'WPC Grouped Product', 'wpc-product-tabs' ) . '</option>';
											}

											if ( function_exists( 'WPCleverWoosg_Helper' ) && ( WPCleverWoosg_Helper()::get_setting( 'position', 'above' ) === 'tab' ) ) {
												echo '<option value="woosg" ' . selected( $tab['type'], 'woosg', false ) . '>' . esc_html__( 'WPC Grouped Product', 'wpc-product-tabs' ) . '</option>';
											}

											if ( class_exists( 'WPCleverWpcpf' ) ) {
												echo '<option value="wpcpf" ' . selected( $tab['type'], 'wpcpf', false ) . '>' . esc_html__( 'WPC Product FAQs', 'wpc-product-tabs' ) . '</option>';
											}

											if ( class_exists( 'WPCleverWpcbr' ) && ( get_option( 'wpcbr_single_position', 'after_meta' ) === 'tab' ) ) {
												// old version
												echo '<option value="wpcbr" ' . selected( $tab['type'], 'wpcbr', false ) . '>' . esc_html__( 'WPC Brands', 'wpc-product-tabs' ) . '</option>';
											}

											if ( function_exists( 'WPCleverWpcbr' ) && ( WPCleverWpcbr()::get_setting( 'single_position', 'after_meta' ) === 'tab' ) ) {
												echo '<option value="wpcbr" ' . selected( $tab['type'], 'wpcbr', false ) . '>' . esc_html__( 'WPC Brands', 'wpc-product-tabs' ) . '</option>';
											}

											if ( ! $product_id ) {
												echo '<option value="dynamic" ' . selected( $tab['type'], 'dynamic', false ) . '>' . esc_html__( 'Dynamic', 'wpc-product-tabs' ) . '</option>';
											}
											?>
                                            <option value="custom" <?php selected( $tab['type'], 'custom' ); ?>><?php esc_html_e( 'Custom', 'wpc-product-tabs' ); ?></option>
                                        </select> </label>
                                </div>
                            </div>
                            <div class="woost-tab-line woost-tab-line-title">
                                <div class="woost-tab-line-label">
									<?php esc_html_e( 'Title', 'wpc-product-tabs' ); ?>
                                </div>
                                <div class="woost-tab-line-value">
                                    <input type="hidden" value="<?php echo esc_attr( $key ); ?>" name="woost_tabs[<?php echo esc_attr( $key ); ?>][key]"/>
                                    <label>
                                        <input type="text" class="woost-tab-title-input" style="width: 100%" name="woost_tabs[<?php echo esc_attr( $key ); ?>][title]" placeholder="<?php echo esc_attr( $tab['type'] ); ?>" value="<?php echo esc_attr( $tab['title'] ); ?>" required/>
                                    </label>
                                </div>
                            </div>
                            <div class="woost-tab-line woost-tab-line-hide woost-tab-line-show-if-dynamic">
                                <div class="woost-tab-line-label">

                                </div>
                                <div class="woost-tab-line-value">
									<?php echo sprintf( /* translators: key */ esc_html__( 'A new field %s will be created, and you can fill in the content for this field on each product.', 'wpc-product-tabs' ), '<code>woost_' . $key . '</code>' ); ?>
                                </div>
                            </div>
                            <div class="woost-tab-line woost-tab-line-hide woost-tab-line-show-if-custom">
                                <div class="woost-tab-line-label">
									<?php esc_html_e( 'Content', 'wpc-product-tabs' ); ?>
                                </div>
                                <div class="woost-tab-line-value">
									<?php
									$editor_id = 'woost-editor-' . $key;
									$content   = stripslashes( html_entity_decode( $tab['content'] ) );

									if ( $new ) {
										echo '<textarea class="woost-textarea" id="' . esc_attr( $editor_id ) . '" name="woost_tabs[' . esc_attr( $key ) . '][content]" rows="10"></textarea>';
									} else {
										wp_editor( $content, $editor_id, [
											'textarea_name' => 'woost_tabs[' . esc_attr( $key ) . '][content]',
											'textarea_rows' => 10
										] );
									}

									if ( ! $product_id ) {
										echo '<p class="description" style="font-size: 10px">' . sprintf( /* translators: shortcode */ esc_html__( 'Use shortcode %s to display this tab content where you want.', 'wpc-product-tabs' ), '<code>[woost_tab_content tab="' . esc_attr( $key ) . '"]</code>' ) . '</p>';
									} else {
										echo '<p class="description" style="font-size: 10px">' . sprintf( /* translators: shortcode */ esc_html__( 'Use shortcode %s to display this tab content where you want.', 'wpc-product-tabs' ), '<code>[woost_tab_content product_id="' . esc_attr( $product_id ) . '" tab="' . esc_attr( $key ) . '"]</code> or <code>[woost_product_tab_content tab="' . esc_attr( $key ) . '"]</code>' ) . '</p>';
									}
									?>
                                </div>
                            </div>
                        </div>
                    </div>
					<?php
				}

				function new_tab( $product_id = 0 ) {
					?>
                    <div class="woost-tabs-new">
                        <input type="button" class="button woost-tab-new" data-product_id="<?php echo esc_attr( $product_id ); ?>" value="<?php esc_attr_e( '+ Add new tab', 'wpc-product-tabs' ); ?>"/>
                    </div>
					<?php
				}

				function get_tabs( $product ) {
					if ( is_numeric( $product ) ) {
						$product_id = $product;
						$product    = wc_get_product( $product_id );
					} elseif ( is_a( $product, 'WC_Product' ) ) {
						$product_id = $product->get_id();
					} else {
						$product    = null;
						$product_id = 0;
					}

					$saved_tabs = [];

					if ( $product && $product_id ) {
						$overwrite  = get_post_meta( $product_id, 'woost_overwrite', true );
						$saved_tabs = get_option( 'woost_tabs', [] );

						if ( $overwrite === 'overwrite' || $overwrite === 'on' ) {
							$saved_tabs = get_post_meta( $product_id, 'woost_tabs', true ) ?: [];
						}

						if ( $overwrite === 'prepend' || $overwrite === 'append' ) {
							$single_tabs = get_post_meta( $product_id, 'woost_tabs', true ) ?: [];

							if ( $overwrite === 'prepend' ) {
								$saved_tabs = array_merge( $single_tabs, $saved_tabs );
							}

							if ( $overwrite === 'append' ) {
								$saved_tabs = array_merge( $saved_tabs, $single_tabs );
							}
						}

						if ( is_array( $saved_tabs ) && ! empty( $saved_tabs ) ) {
							// check apply
							foreach ( $saved_tabs as $key => $saved_tab ) {
								$saved_tab = array_merge( [
									'key'       => '',
									'type'      => 'custom',
									'apply'     => 'all',
									'apply_val' => [],
									'products'  => [],
									'roles'     => [ 'woost_all' ],
									'title'     => '',
									'content'   => ''
								], $saved_tab );

								if ( ! empty( $saved_tab['apply'] ) && ( $saved_tab['apply'] !== 'all' ) && ! empty( $saved_tab['apply_val'] ) && ! has_term( $saved_tab['apply_val'], $saved_tab['apply'], $product_id ) ) {
									// doesn't apply for current product
									unset( $saved_tabs[ $key ] );
								}

								if ( ! empty( $saved_tab['apply'] ) && ( $saved_tab['apply'] === 'products' ) && ! empty( $saved_tab['products'] ) && ! in_array( $product_id, $saved_tab['products'] ) ) {
									// doesn't apply for current product
									unset( $saved_tabs[ $key ] );
								}

								if ( ! self::check_roles( $saved_tab ) ) {
									// doesn't apply for current user role
									unset( $saved_tabs[ $key ] );
								}
							}
						}
					}

					return apply_filters( 'woost_get_tabs', $saved_tabs, $product );
				}

				function get_fields() {
					$fields = [];
					$tabs   = get_option( 'woost_tabs', [] );

					if ( is_array( $tabs ) && ! empty( $tabs ) ) {
						foreach ( $tabs as $key => $tab ) {
							$tab = array_merge( [
								'key'       => '',
								'type'      => 'custom',
								'apply'     => 'all',
								'apply_val' => [],
								'products'  => [],
								'roles'     => [ 'woost_all' ],
								'title'     => '',
								'content'   => ''
							], $tab );

							if ( $tab['type'] === 'dynamic' ) {
								$fields[ $key ] = $tab['title'];
							}
						}
					}

					return apply_filters( 'woost_get_fields', $fields );
				}

				function check_roles( $tab ) {
					$roles = $tab['roles'] ?? [];

					if ( is_string( $roles ) ) {
						$roles = explode( ',', $roles );
					}

					if ( empty( $roles ) || ! is_array( $roles ) || in_array( 'woost_all', $roles ) ) {
						return true;
					}

					if ( is_user_logged_in() ) {
						if ( in_array( 'woost_user', $roles ) ) {
							return true;
						}

						$current_user = wp_get_current_user();

						foreach ( $current_user->roles as $role ) {
							if ( in_array( $role, $roles ) ) {
								return true;
							}
						}
					} else {
						if ( in_array( 'woost_guest', $roles ) ) {
							return true;
						}
					}

					return false;
				}

				function product_tabs( $tabs ) {
					global $product;

					$saved_tabs = self::get_tabs( $product );

					if ( is_array( $saved_tabs ) && ! empty( $saved_tabs ) ) {
						$saved_tab_has_description = $saved_tab_has_reviews = $saved_tab_has_additional_information = $saved_tab_has_woosb = $saved_tab_has_woosb_bundled = $saved_tab_has_woosb_bundles = $saved_tab_has_woosg = $saved_tab_has_wpcpf = $saved_tab_has_wpcbr = false;
						$priority                  = 0;

						foreach ( $saved_tabs as $key => $saved_tab ) {
							$saved_tab_title = apply_filters( 'woost_tab_title', $saved_tab['title'], $key, $saved_tab );
							$saved_tab_type  = $saved_tab['type'];
							$special_tabs    = [
								'description',
								'additional_information',
								'reviews',
								'woosb',
								'woosb_bundled',
								'woosb_bundles',
								'woosg',
								'wpcpf',
								'wpcbr'
							];

							if ( in_array( $saved_tab_type, $special_tabs ) ) {
								$tabs[ $saved_tab_type ]['title']     = sprintf( $saved_tab_title, $product->get_review_count() );
								$tabs[ $saved_tab_type ]['priority']  = $priority;
								${'saved_tab_has_' . $saved_tab_type} = true;
							} else {
								$tab_slug          = 'woost-' . $key;
								$tabs[ $tab_slug ] = [
									'title'    => $saved_tab_title,
									'priority' => $priority,
									'callback' => [ $this, 'tab_content' ]
								];
							}

							$priority ++;
						}

						if ( ! $saved_tab_has_description || ! $product->get_description() ) {
							unset( $tabs['description'] );
						}

						if ( ! $saved_tab_has_reviews || ! comments_open() ) {
							unset( $tabs['reviews'] );
						}

						if ( ! $saved_tab_has_additional_information || ( ! $product->has_attributes() && ! apply_filters( 'wc_product_enable_dimensions_display', $product->has_weight() || $product->has_dimensions() ) ) ) {
							unset( $tabs['additional_information'] );
						}

						if ( ! $saved_tab_has_woosb || ! $product->is_type( 'woosb' ) ) {
							// old version
							unset( $tabs['woosb'] );
						}

						if ( ! $saved_tab_has_woosb_bundled ) {
							unset( $tabs['woosb_bundled'] );
						}

						if ( ! $saved_tab_has_woosb_bundles ) {
							unset( $tabs['woosb_bundles'] );
						}

						if ( ! $saved_tab_has_woosg || ! $product->is_type( 'woosg' ) ) {
							unset( $tabs['woosg'] );
						}

						if ( ! $saved_tab_has_wpcpf ) {
							unset( $tabs['wpcpf'] );
						}

						if ( ! $saved_tab_has_wpcbr ) {
							unset( $tabs['wpcbr'] );
						}
					}

					return $tabs;
				}

				function tab_content( $name, $tab ) {
					global $product;

					if ( ! is_a( $product, 'WC_Product' ) ) {
						return;
					}

					$content    = '';
					$saved_tabs = self::get_tabs( $product );

					if ( is_array( $saved_tabs ) && ! empty( $saved_tabs ) ) {
						$key = str_replace( 'woost-', '', $name );

						if ( ! isset( $saved_tabs[ $key ] ) ) {
							$key = (int) preg_replace( '/\D/', '', $name );
						}

						if ( isset( $saved_tabs[ $key ] ) ) {
							$content = apply_filters( 'woost_tab_heading', '<h2 class="woost-tab-heading">' . esc_html( $saved_tabs[ $key ]['title'] ) . '</h2>', $name, $tab );

							if ( isset( $saved_tabs[ $key ]['type'] ) && ( $saved_tabs[ $key ]['type'] === 'dynamic' ) ) {
								$content .= wpautop( stripslashes( html_entity_decode( get_post_meta( $product->get_id(), 'woost_' . $key, true ) ) ) );
							} else {
								if ( ! empty( $saved_tabs[ $key ]['content'] ) ) {
									$content .= wpautop( stripslashes( html_entity_decode( $saved_tabs[ $key ]['content'] ) ) );
								}
							}
						}
					}

					// ignore woost shortcodes
					$content = str_replace( '[woost_', '[woost_ignored_', $content );
					echo apply_filters( 'woost_tab_content', do_shortcode( $content ), $name, $tab );
				}

				function information_heading( $heading ) {
					global $product;

					$saved_tabs = self::get_tabs( $product );

					if ( is_array( $saved_tabs ) && ! empty( $saved_tabs ) ) {
						foreach ( $saved_tabs as $saved_tab ) {
							if ( ( $saved_tab['type'] === 'additional_information' ) && ! empty( $saved_tab['title'] ) ) {
								return esc_html( $saved_tab['title'] );
							}
						}
					}

					return $heading;
				}

				function description_heading( $heading ) {
					global $product;

					$saved_tabs = self::get_tabs( $product );

					if ( is_array( $saved_tabs ) && ! empty( $saved_tabs ) ) {
						foreach ( $saved_tabs as $saved_tab ) {
							if ( ( $saved_tab['type'] === 'description' ) && ! empty( $saved_tab['title'] ) ) {
								return esc_html( $saved_tab['title'] );
							}
						}
					}

					return $heading;
				}

				function product_data_tabs( $tabs ) {
					$tabs['woost'] = [
						'label'  => esc_html__( 'Product Tabs', 'wpc-product-tabs' ),
						'target' => 'woost_settings'
					];

					return $tabs;
				}

				function product_data_panels() {
					global $post, $thepostid, $product_object;

					if ( $product_object instanceof WC_Product ) {
						$product_id = $product_object->get_id();
					} elseif ( is_numeric( $thepostid ) ) {
						$product_id = $thepostid;
					} elseif ( $post instanceof WP_Post ) {
						$product_id = $post->ID;
					} else {
						$product_id = 0;
					}

					if ( ! $product_id ) {
						?>
                        <div id='woost_settings' class='panel woocommerce_options_panel woost_settings'>
                            <p style="padding: 0 12px; color: #c9356e"><?php esc_html_e( 'Product wasn\'t returned.', 'wpc-product-tabs' ); ?></p>
                        </div>
						<?php
						return;
					}

					$overwrite = get_post_meta( $product_id, 'woost_overwrite', true );
					wp_enqueue_editor();
					?>
                    <div id='woost_settings' class='panel woocommerce_options_panel woost_settings'>
                        <div class="woost-overwrite">
                            <span class="woost-overwrite-label"><?php esc_html_e( 'Product Tabs', 'wpc-product-tabs' ); ?></span>
                            <span class="woost-overwrite-items">
                                <label class="woost-overwrite-item">
                                    <input name="woost_overwrite" type="radio" value="default" <?php echo esc_attr( empty( $overwrite ) || $overwrite === 'default' ? 'checked' : '' ); ?>/> <?php esc_html_e( 'Global', 'wpc-product-tabs' ); ?>
                                </label>
                                <label class="woost-overwrite-item">
                                    <input name="woost_overwrite" type="radio" value="overwrite" <?php echo esc_attr( $overwrite === 'overwrite' || $overwrite === 'on' ? 'checked' : '' ); ?>/> <?php esc_html_e( 'Overwrite', 'wpc-product-tabs' ); ?>
                                </label>
                                <label class="woost-overwrite-item">
                                    <input name="woost_overwrite" type="radio" value="prepend" <?php echo esc_attr( $overwrite === 'prepend' ? 'checked' : '' ); ?>/> <?php esc_html_e( 'Prepend', 'wpc-product-tabs' ); ?>
                                </label>
                                <label class="woost-overwrite-item">
                                    <input name="woost_overwrite" type="radio" value="append" <?php echo esc_attr( $overwrite === 'append' ? 'checked' : '' ); ?>/> <?php esc_html_e( 'Append', 'wpc-product-tabs' ); ?>
                                </label>
                            </span>
                        </div>
                        <div class="woost-tabs-global">
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=wpclever-woost&tab=global' ) ); ?>" target="_blank"><?php esc_html_e( 'Manager Global Tabs', 'wpc-product-tabs' ); ?></a>
                        </div>
                        <div class="woost-tabs-wrapper">
                            <span style="color: #c9356e;">This feature only available on the Premium Version. Click
                                <a href="https://wpclever.net/downloads/product-tabs?utm_source=pro&utm_medium=woost&utm_campaign=wporg" target="_blank">here</a>to buy, just $29!
                            </span>
                        </div>
                        <div class="woost-fields-label">
							<?php esc_html_e( 'Dynamic Fields', 'wpc-product-tabs' ); ?>
                            <span class="woocommerce-help-tip" data-tip="<?php esc_attr_e( 'These fields corresponding to the Global Tabs have dynamic content.', 'wpc-product-tabs' ); ?>"></span>
                        </div>
                        <div class="woost-fields">
							<?php
							$fields = self::get_fields();

							if ( is_array( $fields ) && ! empty( $fields ) ) {
								foreach ( $fields as $key => $field ) {
									echo '<div class="woost-field">';
									echo '<div class="woost-field-head"><span class="woost-field-title">' . esc_html( $field ) . '</span> <span class="woost-field-key">' . esc_html( 'woost_' . $key ) . '</span></div>';
									echo '<div class="woost-field-body">';

									$field_id      = 'woost_' . $key;
									$field_content = get_post_meta( $product_id, $field_id, true );

									wp_editor( $field_content, $field_id, [
										'textarea_name' => $field_id,
										'textarea_rows' => 10
									] );

									echo '</div>';
									echo '</div>';
								}
							} else {
								esc_html_e( 'There are no fields.', 'wpc-product-tabs' );
							}
							?>
                        </div>
                    </div>
					<?php
				}

				function export_process( $value, $meta, $product ) {
					if ( $meta->key === 'woost_tabs' ) {
						$ids = get_post_meta( $product->get_id(), 'woost_tabs', true );

						if ( ! empty( $ids ) && is_array( $ids ) ) {
							return json_encode( $ids );
						}
					}

					return $value;
				}

				function import_process( $object, $data ) {
					if ( isset( $data['meta_data'] ) ) {
						foreach ( $data['meta_data'] as $meta ) {
							if ( $meta['key'] === 'woost_tabs' ) {
								$object->update_meta_data( 'woost_tabs', json_decode( $meta['value'], true ) );
								break;
							}
						}
					}

					return $object;
				}

				function sanitize_array( $arr ) {
					foreach ( (array) $arr as $k => $v ) {
						if ( is_array( $v ) ) {
							$arr[ $k ] = self::sanitize_array( $v );
						} else {
							$arr[ $k ] = sanitize_post_field( 'post_content', $v, 0, 'db' );
						}
					}

					return $arr;
				}

				function generate_key() {
					$key         = '';
					$key_str     = apply_filters( 'woost_key_characters', 'abcdefghijklmnopqrstuvwxyz0123456789' );
					$key_str_len = strlen( $key_str );

					for ( $i = 0; $i < apply_filters( 'woost_key_length', 4 ); $i ++ ) {
						$key .= $key_str[ random_int( 0, $key_str_len - 1 ) ];
					}

					if ( is_numeric( $key ) ) {
						$key = self::generate_key();
					}

					return apply_filters( 'woost_generate_key', $key );
				}
			}

			return WPCleverWoost::instance();
		}

		return null;
	}
}

if ( ! function_exists( 'woost_notice_wc' ) ) {
	function woost_notice_wc() {
		?>
        <div class="error">
            <p><strong>WPC Product Tabs</strong> requires WooCommerce version 3.0 or greater.</p>
        </div>
		<?php
	}
}
