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
}
