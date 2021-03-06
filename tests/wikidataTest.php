<?PHP

# declare(strict_types=1); # PHP7

use PHPUnit\Framework\TestCase;

/**
 * @covers wikidata
 */
final class wikidataTest extends TestCase {

	public function testCanSanitize() { //:void
		$wil = new WikidataItemList() ;

		$value = 'P123' ;
		$wil->sanitizeQ ( $value ) ;
		$this->assertEquals ( 'P123' , $value ) ;

		$value = 'Q123' ;
		$wil->sanitizeQ ( $value ) ;
		$this->assertEquals ( 'Q123' , $value ) ;
		
		$value = '123' ;
		$wil->sanitizeQ ( $value ) ;
		$this->assertEquals ( 'Q123' , $value , 'numeric string => Q' ) ;
		
		$value = 123 ;
		$wil->sanitizeQ ( $value ) ;
		$this->assertEquals ( 'Q123' , $value , 'numeric => Q' ) ;
	}
	
	public function testCanLoadItems() { //:void
		$q = 'Q42' ;
		$wil = new WikidataItemList() ;
		$wil->loadItem ( $q ) ;
		$this->assertTrue ( $wil->hasItem($q) ) ;
		$i = $wil->getItem ( $q ) ;
		$this->assertTrue ( isset($i) ) ;
		$this->i = $i ;
	}

	public function testCanGetLabel() { //:void
		$q = 'Q42' ;
		$wil = new WikidataItemList() ;
		$wil->loadItem ( $q ) ;
		$i = $wil->getItem ( $q ) ;
		$label = $i->getLabel() ;
		$this->assertEquals ( 'Douglas Adams' , $label ) ;
	}
	
}


?>