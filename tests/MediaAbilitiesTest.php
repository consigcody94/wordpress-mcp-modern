<?php
declare(strict_types=1);

namespace WPMCP\Modern\Tests;

use WP_UnitTestCase;

/**
 * Coverage for media abilities (native upload + file resolution).
 */
final class MediaAbilitiesTest extends WP_UnitTestCase {

	/** 1x1 transparent PNG. */
	private const PIXEL_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down(): void {
		$this->remove_added_uploads();
		parent::tear_down();
	}

	private function ability( string $name ) {
		foreach ( wp_get_abilities() as $ability ) {
			if ( $ability->get_name() === $name ) {
				return $ability;
			}
		}
		return null;
	}

	public function test_media_abilities_are_registered(): void {
		$expected = array(
			'wordpress-mcp/wp-list-media',
			'wordpress-mcp/wp-search-media',
			'wordpress-mcp/wp-get-media',
			'wordpress-mcp/wp-update-media',
			'wordpress-mcp/wp-delete-media',
			'wordpress-mcp/wp-upload-media',
			'wordpress-mcp/wp-get-media-file',
		);
		foreach ( $expected as $name ) {
			$this->assertNotNull( $this->ability( $name ), "Ability not registered: {$name}" );
		}
	}

	public function test_upload_then_resolve_media_file(): void {
		$uploaded = $this->ability( 'wordpress-mcp/wp-upload-media' )->execute(
			array(
				'filename' => 'pixel.png',
				'data'     => self::PIXEL_PNG,
				'title'    => 'Test Pixel',
				'alt_text' => 'a pixel',
			)
		);
		$this->assertArrayHasKey( 'id', $uploaded, 'upload should return an attachment id' );
		$id = (int) $uploaded['id'];
		$this->assertGreaterThan( 0, $id );
		$this->assertSame( 'image/png', $uploaded['mime'] );

		$file = $this->ability( 'wordpress-mcp/wp-get-media-file' )->execute(
			array(
				'id'   => $id,
				'size' => 'full',
			)
		);
		$this->assertSame( 'image/png', $file['mime'] );
		$this->assertNotEmpty( $file['url'] );
		$this->assertSame( 'a pixel', $file['alt'] );
	}

	public function test_get_media_file_missing_attachment(): void {
		$result = $this->ability( 'wordpress-mcp/wp-get-media-file' )->execute( array( 'id' => 999999 ) );
		$this->assertSame( 'not_found', $result['error'] ?? null );
	}

	public function test_get_media_file_with_inline_data(): void {
		$uploaded = $this->ability( 'wordpress-mcp/wp-upload-media' )->execute(
			array(
				'filename' => 'pixel.png',
				'data'     => self::PIXEL_PNG,
			)
		);
		$id = (int) $uploaded['id'];

		$file = $this->ability( 'wordpress-mcp/wp-get-media-file' )->execute(
			array(
				'id'           => $id,
				'include_data' => true,
			)
		);
		$this->assertArrayHasKey( 'data', $file, 'include_data should add a base64 data field' );
		$this->assertSame( base64_decode( self::PIXEL_PNG, true ), base64_decode( $file['data'], true ) );
		$this->assertSame( strlen( (string) base64_decode( self::PIXEL_PNG, true ) ), $file['file_bytes'] );
	}

	public function test_get_media_file_as_image_content_block(): void {
		$uploaded = $this->ability( 'wordpress-mcp/wp-upload-media' )->execute(
			array(
				'filename' => 'pixel.png',
				'data'     => self::PIXEL_PNG,
			)
		);
		$id = (int) $uploaded['id'];

		$result = $this->ability( 'wordpress-mcp/wp-get-media-file' )->execute(
			array(
				'id'       => $id,
				'as_image' => true,
			)
		);
		$this->assertSame( 'image', $result['type'] ?? null, 'as_image should return the mcp-adapter image envelope' );
		$this->assertSame( 'image/png', $result['mimeType'] );
		$this->assertSame( base64_decode( self::PIXEL_PNG, true ), $result['results'], 'results must be the raw file bytes' );
	}

	public function test_get_media_file_as_image_rejects_non_images(): void {
		$uploaded = $this->ability( 'wordpress-mcp/wp-upload-media' )->execute(
			array(
				'filename' => 'note.txt',
				'data'     => base64_encode( 'hello mcp' ),
			)
		);
		$id = (int) $uploaded['id'];

		$result = $this->ability( 'wordpress-mcp/wp-get-media-file' )->execute(
			array(
				'id'       => $id,
				'as_image' => true,
			)
		);
		$this->assertSame( 'not_an_image', $result['data_error'] ?? null );
		$this->assertArrayNotHasKey( 'type', $result );
	}

	public function test_get_media_file_as_resource_blob_block(): void {
		$payload  = 'binary-ish payload for the blob resource';
		$uploaded = $this->ability( 'wordpress-mcp/wp-upload-media' )->execute(
			array(
				'filename' => 'note.txt',
				'data'     => base64_encode( $payload ),
			)
		);
		$id = (int) $uploaded['id'];

		$result = $this->ability( 'wordpress-mcp/wp-get-media-file' )->execute(
			array(
				'id'          => $id,
				'as_resource' => true,
			)
		);
		$this->assertSame( 'resource', $result['type'] ?? null, 'as_resource should return the mcp-adapter resource envelope' );
		$this->assertSame( 'text/plain', $result['resource']['mimeType'] );
		$this->assertNotEmpty( $result['resource']['uri'] );
		$this->assertSame( $payload, base64_decode( $result['resource']['blob'], true ), 'blob must be the base64-encoded file contents' );
	}

	public function test_get_media_file_data_respects_size_limit(): void {
		$uploaded = $this->ability( 'wordpress-mcp/wp-upload-media' )->execute(
			array(
				'filename' => 'pixel.png',
				'data'     => self::PIXEL_PNG,
			)
		);
		$id = (int) $uploaded['id'];

		add_filter( 'wpmcp_media_file_max_bytes', static fn() => 1 );
		$file = $this->ability( 'wordpress-mcp/wp-get-media-file' )->execute(
			array(
				'id'           => $id,
				'include_data' => true,
			)
		);
		remove_all_filters( 'wpmcp_media_file_max_bytes' );

		$this->assertSame( 'file_too_large', $file['data_error'] ?? null );
		$this->assertArrayNotHasKey( 'data', $file );
	}
}
