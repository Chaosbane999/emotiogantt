<?php
/**
 * CSV import / export for team members.
 *
 * Export streams every member (including drafts) to a CSV whose columns
 * cover all profile fields, socials, taxonomies, photos (as URLs) and any
 * defined custom fields (cf_* columns). Import upserts from the same
 * format: rows are matched by id, then email, then name; photos are
 * sideloaded from photo_url / hover_photo_url into the media library; and
 * unknown cf_* columns automatically register themselves as custom
 * profile fields.
 *
 * @package Emotio_Team
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ETM_Import_Export {

	const RESULT_TRANSIENT = 'etm_import_result_';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_action( 'admin_post_etm_export', array( __CLASS__, 'handle_export' ) );
		add_action( 'admin_post_etm_import', array( __CLASS__, 'handle_import' ) );
	}

	public static function add_page() {
		add_submenu_page(
			'edit.php?post_type=team_member',
			__( 'Import / Export', 'emotio-team' ),
			__( 'Import / Export', 'emotio-team' ),
			'manage_options',
			'etm-import-export',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * CSV columns, in order. Custom fields are appended as cf_{key}.
	 */
	protected static function columns() {
		$columns = array( 'id', 'name', 'status', 'job_title', 'email', 'phone', 'location', 'pronouns', 'fun_fact', 'departments', 'tags', 'excerpt', 'bio', 'featured', 'order', 'photo_url', 'hover_photo_url' );
		foreach ( array_keys( ETM_Meta::social_networks() ) as $network ) {
			$columns[] = $network;
		}
		foreach ( array_keys( ETM_Settings::custom_fields() ) as $key ) {
			$columns[] = 'cf_' . $key;
		}
		return $columns;
	}

	/* ----------------------------------------------------------- export */

	public static function handle_export() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'emotio-team' ) );
		}
		check_admin_referer( 'etm_export' );

		$template = ! empty( $_GET['template'] );
		$columns  = self::columns();

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="team-' . ( $template ? 'template' : 'export-' . gmdate( 'Ymd-His' ) ) . '.csv"' );

		$out = fopen( 'php://output', 'w' );
		// UTF-8 BOM so Excel opens accents correctly.
		fwrite( $out, "\xEF\xBB\xBF" );
		fputcsv( $out, $columns );

		if ( $template ) {
			$sample = array_fill_keys( $columns, '' );
			$sample = array_merge(
				$sample,
				array(
					'name'        => 'Jane Doe',
					'status'      => 'publish',
					'job_title'   => 'Creative Director',
					'email'       => 'jane@example.com',
					'departments' => 'Design | Leadership',
					'tags'        => 'Branding | UX',
					'excerpt'     => 'Short intro shown on the card.',
					'bio'         => 'Full biography shown on the profile.',
					'featured'    => 'yes',
					'order'       => '0',
					'photo_url'   => 'https://example.com/jane.jpg',
					'linkedin'    => 'https://www.linkedin.com/in/janedoe',
				)
			);
			fputcsv( $out, array_values( array_intersect_key( array_merge( array_fill_keys( $columns, '' ), $sample ), array_flip( $columns ) ) ) );
			fclose( $out );
			exit;
		}

		$members = get_posts(
			array(
				'post_type'      => ETM_CPT::POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page' => -1,
				'orderby'        => 'menu_order title',
				'order'          => 'ASC',
			)
		);

		foreach ( $members as $post ) {
			$row = array();
			foreach ( $columns as $column ) {
				$row[] = self::export_value( $post, $column );
			}
			fputcsv( $out, $row );
		}
		fclose( $out );
		exit;
	}

	protected static function export_value( $post, $column ) {
		switch ( $column ) {
			case 'id':
				return $post->ID;
			case 'name':
				return $post->post_title;
			case 'status':
				return $post->post_status;
			case 'excerpt':
				return $post->post_excerpt;
			case 'bio':
				return $post->post_content;
			case 'featured':
				return ETM_Meta::get( $post->ID, 'featured' ) ? 'yes' : '';
			case 'order':
				return $post->menu_order;
			case 'photo_url':
				return get_the_post_thumbnail_url( $post, 'full' ) ?: '';
			case 'hover_photo_url':
				$hover = absint( ETM_Meta::get( $post->ID, 'hover_image_id' ) );
				return $hover ? ( wp_get_attachment_image_url( $hover, 'full' ) ?: '' ) : '';
			case 'departments':
				return self::term_list( $post->ID, ETM_CPT::TAX_DEPT );
			case 'tags':
				return self::term_list( $post->ID, ETM_CPT::TAX_TAG );
		}
		if ( array_key_exists( $column, ETM_Meta::social_networks() ) ) {
			return ETM_Meta::get( $post->ID, 'social_' . $column );
		}
		if ( 0 === strpos( $column, 'cf_' ) ) {
			return get_post_meta( $post->ID, '_etm_' . $column, true );
		}
		return ETM_Meta::get( $post->ID, $column );
	}

	protected static function term_list( $post_id, $taxonomy ) {
		$terms = get_the_terms( $post_id, $taxonomy );
		if ( ! $terms || is_wp_error( $terms ) ) {
			return '';
		}
		return implode( ' | ', wp_list_pluck( $terms, 'name' ) );
	}

	/* ----------------------------------------------------------- import */

	public static function handle_import() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'emotio-team' ) );
		}
		check_admin_referer( 'etm_import' );

		$result = array(
			'created' => 0,
			'updated' => 0,
			'skipped' => 0,
			'errors'  => array(),
		);

		if ( empty( $_FILES['etm_csv']['tmp_name'] ) || ! is_uploaded_file( $_FILES['etm_csv']['tmp_name'] ) ) {
			$result['errors'][] = __( 'No CSV file was uploaded.', 'emotio-team' );
			self::finish( $result );
		}
		if ( $_FILES['etm_csv']['size'] > 8 * MB_IN_BYTES ) {
			$result['errors'][] = __( 'The CSV is larger than 8 MB.', 'emotio-team' );
			self::finish( $result );
		}

		$update_existing = ! empty( $_POST['etm_update_existing'] );
		$fetch_photos    = ! empty( $_POST['etm_fetch_photos'] );

		$handle = fopen( $_FILES['etm_csv']['tmp_name'], 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $handle ) {
			$result['errors'][] = __( 'The CSV could not be read.', 'emotio-team' );
			self::finish( $result );
		}

		$header = fgetcsv( $handle, 0, ',', '"', '\\' );
		if ( ! $header ) {
			$result['errors'][] = __( 'The CSV appears to be empty.', 'emotio-team' );
			self::finish( $result );
		}
		// Strip BOM, normalise header names.
		$header[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $header[0] );
		$header    = array_map(
			function ( $h ) {
				return strtolower( trim( (string) $h ) );
			},
			$header
		);

		if ( ! in_array( 'name', $header, true ) ) {
			$result['errors'][] = __( 'The CSV needs a "name" column.', 'emotio-team' );
			self::finish( $result );
		}

		// Unknown cf_* columns become field definitions automatically.
		$new_fields = array();
		$defined    = ETM_Settings::custom_fields();
		foreach ( $header as $column ) {
			if ( 0 === strpos( $column, 'cf_' ) ) {
				$key = sanitize_title( substr( $column, 3 ) );
				if ( $key && ! isset( $defined[ $key ] ) ) {
					$new_fields[ $key ] = ucwords( str_replace( array( '-', '_' ), ' ', $key ) );
				}
			}
		}
		if ( $new_fields ) {
			ETM_Settings::register_custom_fields( $new_fields );
		}

		$row_number = 1;
		while ( ( $data = fgetcsv( $handle, 0, ',', '"', '\\' ) ) !== false ) {
			$row_number++;
			if ( 1 === count( $data ) && '' === trim( (string) $data[0] ) ) {
				continue; // Blank line.
			}
			$row = array();
			foreach ( $header as $i => $column ) {
				$row[ $column ] = isset( $data[ $i ] ) ? trim( (string) $data[ $i ] ) : '';
			}
			try {
				self::import_row( $row, $update_existing, $fetch_photos, $result );
			} catch ( Exception $e ) {
				$result['errors'][] = sprintf( /* translators: 1: row number, 2: error */ __( 'Row %1$d: %2$s', 'emotio-team' ), $row_number, $e->getMessage() );
			}
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		self::finish( $result );
	}

	protected static function import_row( array $row, $update_existing, $fetch_photos, array &$result ) {
		$name = sanitize_text_field( $row['name'] ?? '' );
		if ( ! $name ) {
			throw new Exception( __( 'missing name.', 'emotio-team' ) );
		}

		$existing = self::find_existing( $row, $name );
		if ( $existing && ! $update_existing ) {
			$result['skipped']++;
			return;
		}

		$status = in_array( $row['status'] ?? '', array( 'publish', 'draft', 'pending', 'private' ), true ) ? $row['status'] : 'publish';

		$postarr = array(
			'post_type'   => ETM_CPT::POST_TYPE,
			'post_title'  => $name,
			'post_status' => $status,
		);
		if ( isset( $row['bio'] ) && '' !== $row['bio'] ) {
			$postarr['post_content'] = wp_kses_post( $row['bio'] );
		}
		if ( isset( $row['excerpt'] ) && '' !== $row['excerpt'] ) {
			$postarr['post_excerpt'] = sanitize_textarea_field( $row['excerpt'] );
		}
		if ( isset( $row['order'] ) && is_numeric( $row['order'] ) ) {
			$postarr['menu_order'] = (int) $row['order'];
		}

		if ( $existing ) {
			$postarr['ID'] = $existing;
			$post_id       = wp_update_post( $postarr, true );
		} else {
			$post_id = wp_insert_post( $postarr, true );
		}
		if ( is_wp_error( $post_id ) ) {
			throw new Exception( $post_id->get_error_message() );
		}

		// Plain profile fields.
		foreach ( array( 'job_title', 'phone', 'location', 'pronouns', 'fun_fact' ) as $field ) {
			if ( array_key_exists( $field, $row ) ) {
				self::set_meta( $post_id, $field, sanitize_text_field( $row[ $field ] ) );
			}
		}
		if ( array_key_exists( 'email', $row ) ) {
			self::set_meta( $post_id, 'email', sanitize_email( $row['email'] ) );
		}
		if ( array_key_exists( 'featured', $row ) ) {
			self::set_meta( $post_id, 'featured', in_array( strtolower( $row['featured'] ), array( '1', 'yes', 'true', 'y' ), true ) ? 1 : '' );
		}

		// Socials.
		foreach ( array_keys( ETM_Meta::social_networks() ) as $network ) {
			if ( array_key_exists( $network, $row ) ) {
				self::set_meta( $post_id, 'social_' . $network, esc_url_raw( $row[ $network ] ) );
			}
		}

		// Custom fields.
		foreach ( $row as $column => $value ) {
			if ( 0 === strpos( $column, 'cf_' ) ) {
				$key = sanitize_title( substr( $column, 3 ) );
				if ( $key ) {
					self::set_meta( $post_id, 'cf_' . $key, sanitize_text_field( $value ) );
				}
			}
		}

		// Taxonomies ("A | B" or comma-separated).
		if ( array_key_exists( 'departments', $row ) ) {
			self::set_terms( $post_id, ETM_CPT::TAX_DEPT, $row['departments'] );
		}
		if ( array_key_exists( 'tags', $row ) ) {
			self::set_terms( $post_id, ETM_CPT::TAX_TAG, $row['tags'] );
		}

		// Photos — a failed fetch is a warning, not a failed member.
		if ( $fetch_photos ) {
			foreach ( array( 'photo_url' => 'photo', 'hover_photo_url' => 'hover' ) as $column => $which ) {
				if ( empty( $row[ $column ] ) ) {
					continue;
				}
				try {
					self::sideload_photo( $post_id, $row[ $column ], $which );
				} catch ( Exception $e ) {
					$result['errors'][] = $name . ': ' . $e->getMessage();
				}
			}
		}

		if ( $existing ) {
			$result['updated']++;
		} else {
			$result['created']++;
		}
	}

	/**
	 * Match an existing member: id column, then email meta, then exact name.
	 */
	protected static function find_existing( array $row, $name ) {
		if ( ! empty( $row['id'] ) && is_numeric( $row['id'] ) ) {
			$post = get_post( (int) $row['id'] );
			if ( $post && ETM_CPT::POST_TYPE === $post->post_type ) {
				return $post->ID;
			}
		}
		if ( ! empty( $row['email'] ) && is_email( $row['email'] ) ) {
			$found = get_posts(
				array(
					'post_type'      => ETM_CPT::POST_TYPE,
					'post_status'    => 'any',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'meta_key'       => '_etm_email',
					'meta_value'     => sanitize_email( $row['email'] ),
				)
			);
			if ( $found ) {
				return (int) $found[0];
			}
		}
		$found = get_posts(
			array(
				'post_type'      => ETM_CPT::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'title'          => $name,
			)
		);
		return $found ? (int) $found[0] : 0;
	}

	protected static function set_meta( $post_id, $key, $value ) {
		if ( '' === $value || null === $value ) {
			delete_post_meta( $post_id, '_etm_' . $key );
		} else {
			update_post_meta( $post_id, '_etm_' . $key, $value );
		}
	}

	protected static function set_terms( $post_id, $taxonomy, $value ) {
		$names = array_filter( array_map( 'trim', preg_split( '/[|,]/', (string) $value ) ) );
		$ids   = array();
		foreach ( $names as $term_name ) {
			$term = term_exists( $term_name, $taxonomy );
			if ( ! $term ) {
				$term = wp_insert_term( $term_name, $taxonomy );
			}
			if ( ! is_wp_error( $term ) && $term ) {
				$ids[] = (int) ( is_array( $term ) ? $term['term_id'] : $term );
			}
		}
		wp_set_object_terms( $post_id, $ids, $taxonomy );
	}

	/**
	 * Sideload a photo URL into the media library. The source URL is
	 * remembered so re-imports of the same CSV don't duplicate images.
	 */
	protected static function sideload_photo( $post_id, $url, $which ) {
		$url = esc_url_raw( trim( $url ), array( 'http', 'https' ) );
		if ( ! $url ) {
			return;
		}
		$source_key = 'photo' === $which ? '_etm_photo_source' : '_etm_hover_photo_source';
		if ( get_post_meta( $post_id, $source_key, true ) === $url ) {
			return; // Same image as last import.
		}
		// Exporting and re-importing on the same site: reuse the attachment.
		$attachment_id = attachment_url_to_postid( $url );
		if ( ! $attachment_id ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
			$attachment_id = media_sideload_image( $url, $post_id, get_the_title( $post_id ), 'id' );
			if ( is_wp_error( $attachment_id ) ) {
				throw new Exception(
					sprintf( /* translators: 1: photo url, 2: error */ __( 'photo %1$s could not be fetched (%2$s).', 'emotio-team' ), $url, $attachment_id->get_error_message() )
				);
			}
		}
		if ( 'photo' === $which ) {
			set_post_thumbnail( $post_id, $attachment_id );
		} else {
			update_post_meta( $post_id, '_etm_hover_image_id', (int) $attachment_id );
		}
		update_post_meta( $post_id, $source_key, $url );
	}

	protected static function finish( array $result ) {
		set_transient( self::RESULT_TRANSIENT . get_current_user_id(), $result, 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'edit.php?post_type=team_member&page=etm-import-export' ) );
		exit;
	}

	/* --------------------------------------------------------------- UI */

	public static function render_page() {
		$result = get_transient( self::RESULT_TRANSIENT . get_current_user_id() );
		if ( $result ) {
			delete_transient( self::RESULT_TRANSIENT . get_current_user_id() );
		}

		$export_url   = wp_nonce_url( admin_url( 'admin-post.php?action=etm_export' ), 'etm_export' );
		$template_url = wp_nonce_url( admin_url( 'admin-post.php?action=etm_export&template=1' ), 'etm_export' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Import / Export Team', 'emotio-team' ); ?></h1>

			<?php if ( $result ) : ?>
				<?php if ( $result['created'] || $result['updated'] || $result['skipped'] ) : ?>
					<div class="notice notice-success"><p>
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1-3: counts */
								__( 'Import finished: %1$d created, %2$d updated, %3$d skipped.', 'emotio-team' ),
								$result['created'],
								$result['updated'],
								$result['skipped']
							)
						);
						?>
					</p></div>
				<?php endif; ?>
				<?php if ( ! empty( $result['errors'] ) ) : ?>
					<div class="notice notice-error"><p><strong><?php esc_html_e( 'Problems:', 'emotio-team' ); ?></strong></p><ul style="list-style:disc;margin-left:20px;">
						<?php foreach ( array_slice( $result['errors'], 0, 20 ) as $error ) : ?>
							<li><?php echo esc_html( $error ); ?></li>
						<?php endforeach; ?>
						<?php if ( count( $result['errors'] ) > 20 ) : ?>
							<li><?php echo esc_html( sprintf( /* translators: %d: count */ __( '…and %d more.', 'emotio-team' ), count( $result['errors'] ) - 20 ) ); ?></li>
						<?php endif; ?>
					</ul></div>
				<?php endif; ?>
			<?php endif; ?>

			<div class="card" style="max-width:720px;">
				<h2 style="margin-top:0;"><?php esc_html_e( 'Export', 'emotio-team' ); ?></h2>
				<p><?php esc_html_e( 'Download every team member (including drafts) as a CSV — all profile fields, social links, departments, skills/tags, custom fields and photo URLs. Ideal as a backup, or as the starting point for bulk edits in a spreadsheet.', 'emotio-team' ); ?></p>
				<p>
					<a href="<?php echo esc_url( $export_url ); ?>" class="button button-primary"><?php esc_html_e( 'Download CSV export', 'emotio-team' ); ?></a>
					<a href="<?php echo esc_url( $template_url ); ?>" class="button"><?php esc_html_e( 'Download blank template', 'emotio-team' ); ?></a>
				</p>
			</div>

			<div class="card" style="max-width:720px;">
				<h2 style="margin-top:0;"><?php esc_html_e( 'Import', 'emotio-team' ); ?></h2>
				<p><?php esc_html_e( 'Upload a CSV in the same format. Only the "name" column is required. Rows are matched to existing members by id, then email, then exact name. Separate multiple departments or tags with | . Photos are fetched from photo_url / hover_photo_url into your media library. Any cf_ column automatically becomes a custom profile field.', 'emotio-team' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
					<?php wp_nonce_field( 'etm_import' ); ?>
					<input type="hidden" name="action" value="etm_import">
					<p><input type="file" name="etm_csv" accept=".csv,text/csv" required></p>
					<p><label><input type="checkbox" name="etm_update_existing" value="1" checked> <?php esc_html_e( 'Update existing members when a row matches (untick to only add new people)', 'emotio-team' ); ?></label></p>
					<p><label><input type="checkbox" name="etm_fetch_photos" value="1" checked> <?php esc_html_e( 'Fetch photos from photo_url columns into the media library', 'emotio-team' ); ?></label></p>
					<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Import CSV', 'emotio-team' ); ?></button></p>
				</form>
			</div>
		</div>
		<?php
	}
}
