<?php
/**
 * Unit tests for the CSV formula guard in Intercessor\Tools\Abstract_Exporter.
 *
 * @package Intercessor\Tests\Unit\Tools
 */

declare(strict_types=1);

namespace Intercessor\Tests\Unit\Tools;

use Intercessor\Tools\Abstract_Exporter;
use PHPUnit\Framework\TestCase;

class Abstract_ExporterTest extends TestCase {

	/**
	 * @dataProvider formula_like_values
	 */
	public function test_formula_like_strings_are_prefixed( string $value ): void {
		$this->assertSame( "'" . $value, Abstract_Exporter::neutralise_formula( $value ) );
	}

	public static function formula_like_values(): array {
		return [
			'equals' => [ '=HYPERLINK("https://example.com","x")' ],
			'plus'   => [ '+1+1' ],
			'minus'  => [ '-2+3' ],
			'at'     => [ '@SUM(A1:A2)' ],
			'tab'    => [ "\t=1+1" ],
		];
	}

	public function test_ordinary_text_is_unchanged(): void {
		$this->assertSame( 'Please pray for my family', Abstract_Exporter::neutralise_formula( 'Please pray for my family' ) );
		$this->assertSame( 'a = b', Abstract_Exporter::neutralise_formula( 'a = b' ) );
		$this->assertSame( '', Abstract_Exporter::neutralise_formula( '' ) );
	}

	public function test_non_strings_are_unchanged(): void {
		$this->assertSame( -5, Abstract_Exporter::neutralise_formula( -5 ) );
		$this->assertNull( Abstract_Exporter::neutralise_formula( null ) );
	}
}
