<?php

// katalogsuche: sucht im lieferantenkatalog nach $produkt (soweit fuer den Lieferanten implementiert)
// 
// !!! dieses Skript ist nur fuer den _internen_ katalogabgleich aufgrund der Artikelnummer zustaendig!
// !!! fuer die manuelle Suche ist windows/artikelsuche.php da!
//
// $produkt ist entweder eine produkt_id, oder das Ergebnis von sql_produkt_details().
// moegliche rueckgabewerte:
//   1: kein Katalog vom Lieferanten vorhanden (ist kein Fehler)
//   2: keine Artikelnummer - Suche nicht moeglich
//   0 / NULL: Suche ohne (eindeutigen) Treffer
//   array(): enthaelt gefundenen Katalogeintrag
//
function katalogsuche( $produkt ) {
  if( is_numeric( $produkt ) ) {
    $produkt = sql_produkt_details( $produkt );
  }

  $lieferanten_id = $produkt['lieferanten_id'];
  $where = "WHERE lieferanten_id='$lieferanten_id' ";

  $c = sql_select_single_field( "SELECT count(*) AS c FROM lieferantenkatalog $where", 'c' );
  if( ! $c )
    return 1;
  if( ( $artikelnummer = adefault( $produkt, 'artikelnummer', 0 ) ) )
    $where .= " AND artikelnummer='$artikelnummer' ";
  // elseif( ( $bestellnummer = adefault( $produkt, 'bestellnummer', 0 ) ) )
  //  $where .= " AND bestellnummer='$bestellnummer' ";
  else
    return 2;

  return sql_select_single_row( "SELECT * FROM lieferantenkatalog $where " , true );
}


// katalogabgleich
//
// rueckgabe:
//  0: ok
//  1: Katalogeintrag weicht ab (oder kein Preiseintrag in der Foodsoft-Datenbank)
//  2: Katalogsuche fehlgeschlagen (kann auch heissen: kein Katalog dieses Lieferanten erfasst)
// 
//
function katalogabgleich(
  $produkt_id
, $detail = false  // Katalogeintrag auch anzeigen?
, & $preiseintrag_neu = array() // aus Katalogeintrag Vorschlag fuer Preiseintrag generieren
) {
  global $mwst_default;

  $artikel = sql_produkt_details( $produkt_id );
  $prgueltig = $artikel['zeitstart'];
  $neednewprice = false;

  $katalogeintrag = katalogsuche( $artikel );
  if( $katalogeintrag == 1 ) {
    div_msg( 'alert', 'Katalogsuche: kein Katalog dieses Lieferanten erfasst!' );
    return 2;
  } else if( $katalogeintrag == 2 ) {
    div_msg( 'warn', 'Katalogsuche: Artikelnummer des Produktes fehlt --- Suche nicht moeglich!' );
    return 2;
  } else if( ! $katalogeintrag ) {
    div_msg( 'warn', 'Katalogsuche fehlgeschlagen oder ohne Treffer' );
    return 2;
  }

  $katalog_datum = $katalogeintrag["katalogdatum"];
  $katalog_typ = $katalogeintrag["katalogtyp"];
  $katalog_artikelnummer = $katalogeintrag["artikelnummer"];
  $katalog_bestellnummer = $katalogeintrag["bestellnummer"];
  $katalog_name = $katalogeintrag["name"];
  $katalog_einheit = str_replace( ',', '.' , $katalogeintrag["liefereinheit"] );
  $katalog_gebindegroesse = str_replace( ',', '.' , $katalogeintrag["gebinde"] );
  $katalog_herkunft =  $katalogeintrag["herkunft"];
  $katalog_verband = $katalogeintrag["verband"];
  $katalog_netto = $katalogeintrag["preis"];


  if( ! kanonische_einheit( $katalog_einheit, &$kan_liefereinheit, &$kan_liefermult, false ) ) {
    div_msg( 'warn', "Katalogsuche: unbekannte Einheit: $katalog_einheit" );
    return 2;
  }

  $have_mwst = false; // ist die mwst im katalog gelisted? (terra ja, andere nicht!)
  switch( $katalogeintrag['katalogformat'] ) {
    case 'terra':
      $have_mwst = true;

      // setze:
      //   - $liefereinheit: auf diese bezieht sich der preis $katalog_netto,
      //     ebenso der wie der 'lieferpreis' in tabelle 'produktpreise'
      //   - $liefergebinde in vielfachen der $liefereinheit
      //     (achtung: 'gebindegroesse' in tabelle 'produktpreise' ist in v-einheiten!)
      switch( $kan_liefereinheit ) {
        case 'g':
          $liefereinheit = '1000 g';
          $liefergebinde = $katalog_gebindegroesse * $kan_liefermult / 1000.0;
          $kan_liefermult = 1000.0;
          break;
        case 'ml':
          $liefereinheit = '1000 ml';
          $liefergebinde = $katalog_gebindegroesse * $kan_liefermult / 1000.0;
          $kan_liefermult = 1000.0;
          break;
        default:
          $liefereinheit = "1 $kan_liefereinheit";
          $liefergebinde = $katalog_gebindegroesse * $kan_liefermult;
          $kan_liefermult = 1;
          break;
      }

      // default fuer v-einheit (nur relevant, falls gar keine oder inkompatible gesetzt)
      switch( $kan_liefereinheit ) {
        case 'KI':
        case 'PA':
        case 'GB':
          $verteileinheit_default = '1 ST';
          $lv_faktor_default = 1; // TODO: aus katalogtext parsen?
          break;
        case 'g':
          $verteileinheit_default = '500 g';
          $lv_faktor_default = 2;
          break;
        case 'ml':
          $verteileinheit_default = '1000 ml';
          $lv_faktor_default = 1;
          break;
        default:
          $verteileinheit_default = $liefereinheit;
          $lv_faktor_default = 1;
          break;
      }

      break;

    case 'bode':

      $liefergebinde = $katalog_gebindegroesse;
      $liefereinheit = "$kan_liefermult $kan_liefereinheit";  // oder = $katalog_einheit ???
      $verteileinheit_default = $liefereinheit;
      $lv_faktor_default = 1;

      break;

    case 'rapunzel':

      $liefergebinde = $katalog_gebindegroesse;
      $liefereinheit = "$kan_liefermult $kan_liefereinheit";
      $verteileinheit_default = $liefereinheit;
      $lv_faktor_default = 1;

      break;

    default:
    case 'keins':
      open_div( 'warn', '', "unbekanntes oder undefiniertes Katalogformat --- Katalogabgleich nicht moeglich" );
      return 2;
  }

  if( $have_mwst) {
    $katalog_mwst = $katalogeintrag["mwst"];
    $katalog_brutto = $katalog_netto * (1 + $katalog_mwst / 100.0 );
  } else {
    $katalog_mwst = 'n/a';
    $katalog_brutto = 'n/a';
  }

  if( $detail ) {
    open_fieldset( 'big_form', '', "Lieferantenkatalog: Artikel gefunden in Katalog $katalog_typ / $katalog_datum" );
      open_table( 'list hfill' );
          open_th( '', "title='Artikelnummer'", 'A-Nr.' );
          open_th( '', "title='Bestellnummer'", 'B-Nr.' );
          open_th( '', '', 'Bezeichnung' );
          open_th( '', '', 'Einheit' );
          open_th( '', '', 'Gebinde' );
          open_th( '', '', 'Land' );
          open_th( '', '', 'Verband' );
          open_th( '', '', 'Netto' );
          open_th( '', '', 'MWSt' );
          open_th( '', '', 'Brutto' );
        open_tr();
          open_td( '', '', $katalog_artikelnummer );
          open_td( '', '', $katalog_bestellnummer );
          open_td( '', '', $katalog_name );
          open_td( '', '', $katalog_einheit );
          open_td( '', '', $katalog_gebindegroesse );
          open_td( '', '', $katalog_herkunft );
          open_td( '', '', $katalog_verband );
          open_td( '', '', $katalog_netto );
          open_td( '', '', $katalog_mwst );
          open_td( '', '', $katalog_brutto );
        open_tr();
          open_td( 'left small top', "colspan='3'", 'Interpretation der Foodsoft:' );
          open_td( 'center small top', "colspan='2'", "1 Gebinde = $liefergebinde * ($liefereinheit)" );
          open_td( '', "colspan='2'", '');
          open_td( 'center small top', "colspan='3'", "Preis gilt pro $liefereinheit" );
      close_table();
    close_fieldset();
  }

  ////////////////////////////////
  // aktuellsten preiseintrag mit Katalogeintrag vergleichen,
  // Vorlage fuer neuen preiseintrag mit Katalogdaten vorbesetzen:
  // - die L-felder werden immer aus dem katalog uebernommen, 
  // - die V-felder wenn moeglich aus dem letzten Preiseintrag
  //
  $preiseintrag_neu = array();

  $preiseintrag_neu['liefereinheit'] = $liefereinheit;
  $preiseintrag_neu['lieferpreis'] = $katalog_netto;
  $preiseintrag_neu['bestellnummer'] = $katalog_bestellnummer;
  if( $have_mwst ) {
    $preiseintrag_neu['mwst'] = $katalog_mwst;
  }

  if( $prgueltig ) {

    // liefereinheit und mwst sollten mit katalog uebereinstimmen:
    //
    if( $preiseintrag_neu['liefereinheit'] != $artikel['liefereinheit'] ) {
      $neednewprice = TRUE;
      open_div( 'warn', '', "Problem: L-Einheit stimmt nicht:
        <p class='li'>Katalog: <kbd>{$preiseintrag_neu['liefereinheit']}</kbd></p>
        <p class='li'>Foodsoft: <kbd>{$artikel['liefereinheit']}</kbd></p>
        <div class='small'>die L-Einheit sollte dem Einzelpreis im Katalog zugrundeliegen</div>
      " );
    }
    if( $have_mwst ) {
      $preiseintrag_neu['mwst'] = $katalog_mwst;
      if( abs( $preiseintrag_neu['mwst'] - $artikel['mwst'] ) > 0.005 ) {
        $neednewprice = TRUE;
        open_div( 'warn', '', "Problem: MWSt-Satz stimmt nicht:
          <p class='li'>Katalog: <kbd>{$preiseintrag_neu['mwst']}</kbd></p>
          <p class='li'>Foodsoft: <kbd>{$artikel['mwst']}</kbd></p>
        " );
      }
    }

    $preiseintrag_neu['verteileinheit'] =
      ( $artikel['verteileinheit'] ? $artikel['verteileinheit'] : $verteileinheit_default );

    switch( $kan_liefereinheit ) {
      case 'KI':
      case 'PA':
      case 'GB':
        // verteileinheit darf von liefereinheit abweichen:
        break;
      default:
        if( $kan_liefereinheit !== $artikel['kan_verteileinheit'] ) {
          $preiseintrag_neu['verteileinheit'] = $verteileinheit_default;
          $neednewprice = TRUE;
          open_div( 'alert', '', "Warnung: Einheiten inkompatibel:
                  <p class='li'>Katalog: <kbd>$kan_liefereinheit</kbd></p>
                  <p class='li'>Verteilung: <kbd>{$artikel['kan_verteileinheit']}</kbd></p>
                  <div class='small'>die Einheiten sollten in der Regel Vielfache voneinander sein - Ausnahmen auf eigene Gefahr!</div>
          " );
        }
    }

    kanonische_einheit( $preiseintrag_neu['verteileinheit'], &$kan_verteileinheit_neu, &$kan_verteilmult_neu );

    if( $kan_liefereinheit !== $kan_verteileinheit_neu ) {
      // keine automatische umrechnung moeglich; wir uebernehmen die alten werte, die
      // manuell geprueft und bei bedarf korrigiert werden muessen:
      if( $artikel['lv_faktor'] > 0.001 ) {
        $preiseintrag_neu['lv_faktor'] = $artikel['lv_faktor'];
        $preiseintrag_neu['gebindegroesse'] = $liefergebinde * $preiseintrag_neu['lv_faktor'];
        if( abs( $preiseintrag_neu['gebindegroesse'] - $artikel['gebindegroesse'] ) > 0.001 ) {
          $neednewprice = TRUE;
          open_div( 'warn', '', "Problem: Gebindegroessen oder Umrechnung Liefer/Verteileinheit stimmen nicht:
            <p class='li'>Katalog: <kbd>$liefergebinde * $liefereinheit</kbd></p>
            <p class='li'>Foodsoft: <kbd>{$artikel['gebindegroesse']} * {$artikel['verteileinheit']}</kbd></p>
            <div class='small'>Bitte manuell pr&uuml;en und neuen Preiseintrag erfassen!</div>
          " );
        }
      } else {
        if( $artikel['gebindegroesse'] > 0.001 ) {
          $preiseintrag_neu['lv_faktor'] = $liefergebinde / $artikel['gebindegroesse'];
        } else {
          $preiseintrag_neu['lv_faktor'] = 1.0;
        }
        $neednewprice = TRUE;
        open_div( 'warn', '', "Problem: Umrechnungsfaktor von Liefer und Verteileinheit noch nicht erfasst.
            <div class='small'>Bitte manuell pr&uuml;en und neuen Preiseintrag erfassen!</div>
        " );
      }

    } else {

      // einheiten sind kompatibel: wir setzen die vorlage auf aus katalog berechnete werte und vergleichen
      // anschliessend mit dem ist-zustand:

      $preiseintrag_neu['lv_faktor'] = $kan_liefermult / $kan_verteilmult_neu;
      $preiseintrag_neu['gebindegroesse'] = $liefergebinde * $preiseintrag_neu['lv_faktor'];
      if( abs( $preiseintrag_neu['gebindegroesse'] - $artikel['gebindegroesse'] ) > 0.001 ) {
        $neednewprice = TRUE;
        open_div( 'warn', '', "Problem: Gebindegroessen stimmen nicht:
          <p class='li'>Katalog: <kbd>$liefergebinde * $liefereinheit</kbd></p>
          <p class='li'>Foodsoft: <kbd>{$artikel['gebindegroesse']} * {$artikel['verteileinheit']}</kbd></p>
        " );
      }
    }

    if( abs( $preiseintrag_neu['lieferpreis'] - $artikel['nettolieferpreis'] ) > 0.005 ) {
      $neednewprice = TRUE;
      open_div( 'warn' );
        echo "Problem: Preise stimmen nicht (beide Brutto ohne Pfand):
          <p class='li'>Katalog: <kbd>$katalog_netto / $liefereinheit</kbd></p>
          <p class='li'>Foodsoft: <kbd>{$artikel['nettopreis']} / {$artikel['verteileinheit']} ";
        if( $liefereinheit != $artikel['verteileinheit'] ) {
          echo " = {$artikel['nettolieferpreis']} / $liefereinheit";
        }
        echo "</kbd></p>";
      close_div();
    }

    if( $katalog_bestellnummer != $artikel['bestellnummer'] ) {
      $neednewprice = TRUE;
      open_div( 'warn', '', "Problem: Bestellnummern stimmen nicht:
        <p class='li'>Katalog: <kbd>$katalog_bestellnummer</kbd></p>
        <p class='li'>Foodsoft: <kbd>{$artikel['bestellnummer']}</kbd></p>
      " );
    }

  } else {
    // kein aktuell gueltiger Preiseintrag: wir erzeugen defaults aus Katalogdaten...
    $preiseintrag_neu['verteileinheit'] = $verteileinheit_default;
    $preiseintrag_neu['lv_faktor'] = $lv_faktor_default;
    $preiseintrag_neu['gebindegroesse'] = $liefergebinde * $lv_faktor_default;
    // ...und empfehlen einen neuen Preiseintrag:
    $neednewprice = TRUE;
  }

  return ( $neednewprice ? 1 : 0 );
}

?>
