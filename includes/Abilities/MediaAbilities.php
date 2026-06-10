<?php
declare(strict_types=1);

namespace WPMCP\Modern\Abilities;

/**
 * Media library capabilities, mirroring the legacy wordpress-mcp media tools.
 * Reads/update/delete proxy the core /wp/v2/media route; upload is native
 * (base64 -> attachment) and file-info returns the resolved URL + metadata,
 * with opt-in base64 file contents (size-capped) for clients that need bytes,
 * or a native MCP image content block via as_image.
 */
final class MediaAbilities {

	public static function register(): void {
		foreach ( self::definitions() as $def ) {
			if ( 'rest' === ( $def['kind'] ?? 'native' ) ) {
				RestProxyAbility::register( $def );
			} else {
				NativeAbility::register( $def );
			}
		}
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function definitions(): array {
		$ns = AbilityRegistrar::NS;

		$list_schema = array(
			'type'       => 'object',
			'properties' => array(
				'search'     => array( 'type' => 'string' ),
				'media_type' => array( 'type' => 'string', 'enum' => array( 'image', 'video', 'audio', 'application', 'text' ) ),
				'mime_type'  => array( 'type' => 'string' ),
				'parent'     => array( 'type' => 'integer', 'description' => 'Limit to media attached to this post ID.' ),
				'author'     => array( 'type' => 'integer' ),
				'per_page'   => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 10 ),
				'page'       => array( 'type' => 'integer', 'minimum' => 1, 'default' => 1 ),
				'orderby'    => array( 'type' => 'string', 'enum' => array( 'date', 'title', 'id' ), 'default' => 'date' ),
				'order'      => array( 'type' => 'string', 'enum' => array( 'asc', 'desc' ), 'default' => 'desc' ),
			),
		);

		return array(
			array(
				'name'         => "$ns/wp-list-media",
				'mcp_name'     => 'wp_list_media',
				'kind'         => 'rest',
				'label'        => 'List media',
				'description'  => 'List media library items with optional filters and pagination.',
				'type'         => 'read',
				'method'       => 'GET',
				'route'        => '/wp/v2/media',
				'capability'   => 'read',
				'input_schema' => $list_schema,
			),
			array(
				'name'         => "$ns/wp-search-media",
				'mcp_name'     => 'wp_search_media',
				'kind'         => 'rest',
				'label'        => 'Search media',
				'description'  => 'Search media library items by title, caption, or description.',
				'type'         => 'read',
				'method'       => 'GET',
				'route'        => '/wp/v2/media',
				'capability'   => 'read',
				'input_schema' => $list_schema,
			),
			array(
				'name'         => "$ns/wp-get-media",
				'mcp_name'     => 'wp_get_media',
				'kind'         => 'rest',
				'label'        => 'Get media',
				'description'  => 'Retrieve a single media item by ID.',
				'type'         => 'read',
				'method'       => 'GET',
				'route'        => '/wp/v2/media/{id}',
				'capability'   => 'read',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'      => array( 'type' => 'integer', 'description' => 'The media (attachment) ID.' ),
						'context' => array( 'type' => 'string', 'enum' => array( 'view', 'edit' ), 'default' => 'view' ),
					),
					'required'   => array( 'id' ),
				),
			),
			array(
				'name'         => "$ns/wp-update-media",
				'mcp_name'     => 'wp_update_media',
				'kind'         => 'rest',
				'label'        => 'Update media',
				'description'  => 'Update a media item\'s title, alt text, caption, or description.',
				'type'         => 'update',
				'method'       => 'POST',
				'route'        => '/wp/v2/media/{id}',
				'capability'   => 'upload_files',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'          => array( 'type' => 'integer', 'description' => 'The media (attachment) ID.' ),
						'title'       => array( 'type' => 'string' ),
						'alt_text'    => array( 'type' => 'string' ),
						'caption'     => array( 'type' => 'string' ),
						'description' => array( 'type' => 'string' ),
					),
					'required'   => array( 'id' ),
				),
			),
			array(
				'name'         => "$ns/wp-delete-media",
				'mcp_name'     => 'wp_delete_media',
				'kind'         => 'rest',
				'label'        => 'Delete media',
				'description'  => 'Delete a media item by ID (use force to permanently delete).',
				'type'         => 'delete',
				'method'       => 'DELETE',
				'route'        => '/wp/v2/media/{id}',
				'capability'   => 'delete_posts',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'    => array( 'type' => 'integer', 'description' => 'The media (attachment) ID.' ),
						'force' => array( 'type' => 'boolean', 'default' => true ),
					),
					'required'   => array( 'id' ),
				),
			),
			array(
				'name'         => "$ns/wp-upload-media",
				'mcp_name'     => 'wp_upload_media',
				'kind'         => 'native',
				'label'        => 'Upload media',
				'description'  => 'Upload a new media item from base64-encoded file data.',
				'type'         => 'create',
				'capability'   => 'upload_files',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'filename' => array( 'type' => 'string', 'description' => 'File name including extension (e.g. "photo.png").' ),
						'data'     => array( 'type' => 'string', 'description' => 'Base64-encoded file contents.' ),
						'title'    => array( 'type' => 'string' ),
						'alt_text' => array( 'type' => 'string' ),
						'caption'  => array( 'type' => 'string' ),
					),
					'required'   => array( 'filename', 'data' ),
				),
				'execute'      => static function ( array $input ) {
					if ( empty( $input['filename'] ) || empty( $input['data'] ) ) {
						return array( 'error' => 'missing_params', 'message' => 'filename and base64 data are required.' );
					}
					$bytes = base64_decode( (string) $input['data'], true );
					if ( false === $bytes ) {
						return array( 'error' => 'invalid_base64', 'message' => 'data must be base64-encoded.' );
					}
					$filename = sanitize_file_name( (string) $input['filename'] );
					$upload   = wp_upload_bits( $filename, null, $bytes );
					if ( ! empty( $upload['error'] ) ) {
						return array( 'error' => 'upload_failed', 'message' => $upload['error'] );
					}
					$filetype  = wp_check_filetype( $upload['file'] );
					$attach_id = wp_insert_attachment(
						array(
							'post_mime_type' => $filetype['type'],
							'post_title'     => ! empty( $input['title'] ) ? $input['title'] : $filename,
							'post_excerpt'   => $input['caption'] ?? '',
							'post_status'    => 'inherit',
						),
						$upload['file'],
						0,
						true
					);
					if ( is_wp_error( $attach_id ) ) {
						return array( 'error' => $attach_id->get_error_code(), 'message' => $attach_id->get_error_message() );
					}
					require_once ABSPATH . 'wp-admin/includes/image.php';
					wp_update_attachment_metadata( $attach_id, wp_generate_attachment_metadata( $attach_id, $upload['file'] ) );
					if ( ! empty( $input['alt_text'] ) ) {
						update_post_meta( $attach_id, '_wp_attachment_image_alt', sanitize_text_field( $input['alt_text'] ) );
					}
					return array(
						'id'   => (int) $attach_id,
						'url'  => wp_get_attachment_url( $attach_id ),
						'mime' => $filetype['type'],
					);
				},
			),
			array(
				'name'         => "$ns/wp-get-media-file",
				'mcp_name'     => 'wp_get_media_file',
				'kind'         => 'native',
				'label'        => 'Get media file',
				'description'  => 'Resolve a media item\'s file URL and metadata for a given size; optionally include the file contents as base64, or return images as a native MCP image content block.',
				'type'         => 'read',
				'capability'   => 'read',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'           => array( 'type' => 'integer', 'description' => 'The media (attachment) ID.' ),
						'size'         => array( 'type' => 'string', 'enum' => array( 'thumbnail', 'medium', 'large', 'full' ), 'default' => 'full' ),
						'include_data' => array( 'type' => 'boolean', 'default' => false, 'description' => 'Include the file contents base64-encoded in the "data" field (subject to a size limit).' ),
						'as_image'     => array( 'type' => 'boolean', 'default' => false, 'description' => 'Return the file as a native MCP image content block instead of JSON metadata (images only; subject to the same size limit). Takes precedence over include_data.' ),
						'as_resource'  => array( 'type' => 'boolean', 'default' => false, 'description' => 'Return the file (any type — audio, PDF, archives…) as an MCP embedded blob resource instead of JSON metadata (subject to the same size limit). as_image takes precedence for images.' ),
					),
					'required'   => array( 'id' ),
				),
				'execute'      => static function ( array $input ) {
					$id   = (int) ( $input['id'] ?? 0 );
					$post = get_post( $id );
					if ( ! $post || 'attachment' !== $post->post_type ) {
						return array( 'error' => 'not_found', 'message' => 'Attachment not found.' );
					}
					$size = (string) ( $input['size'] ?? 'full' );
					$src  = wp_get_attachment_image_src( $id, $size );
					$mime = get_post_mime_type( $id );
					$out  = array(
						'id'     => $id,
						'url'    => $src ? $src[0] : wp_get_attachment_url( $id ),
						'mime'   => $mime,
						'alt'    => get_post_meta( $id, '_wp_attachment_image_alt', true ),
						'width'  => $src[1] ?? null,
						'height' => $src[2] ?? null,
					);

					$as_image    = ! empty( $input['as_image'] );
					$as_resource = ! empty( $input['as_resource'] );
					if ( ! $as_image && ! $as_resource && empty( $input['include_data'] ) ) {
						return $out;
					}

					if ( $as_image && 0 !== strpos( (string) $mime, 'image/' ) ) {
						$out['data_error'] = 'not_an_image';
						return $out;
					}

					$path = self::resolve_file_path( $id, $size );
					if ( ! $path || ! is_readable( $path ) ) {
						$out['data_error'] = 'file_not_readable';
						return $out;
					}
					$max_bytes = (int) apply_filters( 'wpmcp_media_file_max_bytes', 5 * MB_IN_BYTES );
					$bytes     = filesize( $path );
					if ( false === $bytes || $bytes > $max_bytes ) {
						$out['data_error'] = 'file_too_large';
						$out['file_bytes'] = false === $bytes ? null : $bytes;
						return $out;
					}

					if ( $as_image ) {
						// mcp-adapter's ToolsHandler turns this envelope into a native
						// MCP ImageContent block (it base64-encodes the raw bytes itself).
						return array(
							'type'     => 'image',
							'results'  => (string) file_get_contents( $path ),
							'mimeType' => $mime,
						);
					}

					if ( $as_resource ) {
						// ToolsHandler turns this envelope into an EmbeddedResource block
						// (blob must be base64 already). Used for audio + arbitrary binaries;
						// mcp-adapter 0.5 has no AudioContent envelope, so audio ships as a
						// blob resource too.
						return array(
							'type'     => 'resource',
							'resource' => array(
								'uri'      => (string) $out['url'],
								'blob'     => base64_encode( (string) file_get_contents( $path ) ),
								'mimeType' => $mime,
							),
						);
					}

					$out['data']       = base64_encode( (string) file_get_contents( $path ) );
					$out['file_bytes'] = $bytes;
					return $out;
				},
			),
		);
	}

	/**
	 * Absolute filesystem path for an attachment at a given size ('full' or a
	 * registered intermediate size), or null when unresolvable.
	 */
	private static function resolve_file_path( int $id, string $size ): ?string {
		$path = get_attached_file( $id );
		if ( 'full' !== $size ) {
			$intermediate = image_get_intermediate_size( $id, $size );
			if ( $intermediate && ! empty( $intermediate['path'] ) ) {
				$uploads = wp_get_upload_dir();
				$path    = trailingslashit( $uploads['basedir'] ) . $intermediate['path'];
			}
		}
		return is_string( $path ) && '' !== $path ? $path : null;
	}
}
