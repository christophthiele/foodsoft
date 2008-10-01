<?php
//
// bestellschein.php:
// - wenn bestell_id (oder bestellungs_id...) uebergeben:
//   detailanzeige, abhaengig vom status der bestellung
// - wenn keine bestell_id uebergeben:
//   auswahlliste aller bestellungen zeigen
//   (ggf. mit filter "status")
//

error_reporting(E_ALL);
//$_SESSION['LEVEL_CURRENT'] = LEVEL_IMPORTANT;

assert( $angemeldet ) or exit();

get_http_var( 'bestell_id', 'u', 0, true );

get_http_var( 'action', 'w', '' );
$readonly and $action = '';
switch( $action ) {
  case 'changeState':
    nur_fuer_dienst(1,3,4);
    need_http_var( 'change_id', 'u' );
    need_http_var( 'change_to', 'w' );
    if( changeState( $change_id, $change_to ) ) {
      if( ! $bestell_id ) {  // falls nicht bereits in detailanzeige:
        switch( $change_to ) {
          case STATUS_LIEFERANT:   // bestellschein oder ...
          case STATUS_VERTEILT:    // ... lieferschein anzeigen:
            echo fc_openwindow( 'bestellschein', "bestell_id=$change_id" );
          break;
        }
      }
    }
    break;

  case 'insert':
    nur_fuer_dienst(1,3,4);
    need( getState( $bestell_id ) < STATUS_ABGERECHNET, "Änderung nicht möglich: Bestellung ist bereits abgerechnet!" );
    need_http_var( 'produkt_id', 'u' );
    need_http_var( 'menge', 'f' );
    if( $bestell_id && ( $menge > 0 ) ) {
      zusaetzlicheBestellung( $produkt_id, $bestell_id, $menge );
    }
    break;

  case 'delete':
    nur_fuer_dienst(4);
    need_http_var( 'delete_id', 'U' );
    need( references_gesamtbestellung( $bestell_id ) == 0 );
    doSql( "DELETE FROM gesamtbestellungen WHERE id = $delete_id " );
    unset( $bestell_id );
    unset( $self_fields['bestell_id'] );
    break;

  default:
    break;
}

if( ! $bestell_id ) {
  // auswahl praesentieren, abhaengig von $state oder $window:
  get_http_var( 'state', 'u', 0, true );
  $result = sql_bestellungen( $state );
  select_bestellung_view($result, 'Liste der Bestellungen', $hat_dienst_IV, $dienst > 0 );
  return;
}

get_http_var( 'gruppen_id', 'u', 0, true );

if( $gruppen_id and ! in_array( $gruppen_id, $specialgroups ) ) {
  if( $gruppen_id != $login_gruppen_id )
    nur_fuer_dienst(4);
  $gruppen_name = sql_gruppenname($gruppen_id);
}
$state = getState($bestell_id);

$default_spalten = PR_COL_NAME | PR_COL_LPREIS | PR_COL_VPREIS | PR_COL_MWST | PR_COL_PFAND;
switch($state){    // anzeigedetails abhaengig vom Status auswaehlen
  case STATUS_BESTELLEN:
    $editable = FALSE;
    if( $gruppen_id ) {
      $default_spalten |= ( PR_COL_BESTELLMENGE | PR_COL_ENDSUMME );
    } else {
      $default_spalten
        |= ( PR_COL_BESTELLMENGE | PR_COL_BESTELLGEBINDE | PR_COL_NETTOSUMME | PR_COL_BRUTTOSUMME
             | PR_ROWS_NICHTGEFUELLT );
    }
    $title="Bestellschein (vorläufig)";
    break;
  case STATUS_LIEFERANT:
    $editable= FALSE;
    if( $gruppen_id ) {
      $default_spalten |= ( PR_COL_BESTELLMENGE | PR_COL_LIEFERMENGE | PR_COL_ENDSUMME );
    } else {
      $default_spalten
        |= ( PR_COL_BESTELLMENGE | PR_COL_LIEFERMENGE | PR_COL_LIEFERGEBINDE
             | PR_COL_NETTOSUMME | PR_COL_BRUTTOSUMME | PR_ROWS_NICHTGEFUELLT );
    }
    $title="Bestellschein";
    // $selectButtons = array("zeigen" => "bestellschein", "pdf" => "bestellt_faxansicht" );
    break;
  case STATUS_VERTEILT:
  case STATUS_ABGERECHNET:
    if( $gruppen_id ) {
      $editable= FALSE;
      $default_spalten |= ( PR_COL_BESTELLMENGE | PR_COL_LIEFERMENGE | PR_COL_ENDSUMME );
    } else {
      // ggf. liefermengen aendern lassen:
      $editable = (!$readonly) && ( $hat_dienst_I or $hat_dienst_III or $hat_dienst_IV && ( $state == STATUS_VERTEILT ) );
      $default_spalten
        |= ( PR_COL_BESTELLMENGE | PR_COL_LIEFERMENGE | PR_COL_LIEFERGEBINDE
             | PR_COL_NETTOSUMME | PR_COL_BRUTTOSUMME | PR_ROWS_NICHTGEFUELLT );
    }
    $title="Lieferschein";
    break;
  default: 
    ?> <div class='warn'>Keine Detailanzeige verfügbar</div> <?
    return;
}

get_http_var( 'spalten', 'w', $default_spalten, true );

  // liefermengen aktualisieren:
//  Hier werden die vom Formular übergebenen Werte ausgewertet
//  FIXME in obiges switch-statement integrieren
  //
  if( $editable and $state == STATUS_VERTEILT ) {
    $produkte = sql_bestellprodukte($bestell_id, 0, 0 );
    while  ($produkte_row = mysql_fetch_array($produkte)) {
      $produkt_id =$produkte_row['produkt_id'];
      if( get_http_var( 'liefermenge'.$produkt_id, 'f' ) ) {
        preisdatenSetzen( & $produkte_row );
        $mengenfaktor = $produkte_row['mengenfaktor'];
        $liefermenge = $produkte_row['liefermenge'] / $mengenfaktor;
        if( abs( ${"liefermenge$produkt_id"} - $liefermenge ) > 0.001 ) {
          $liefermenge = ${"liefermenge$produkt_id"};
          changeLiefermengen_sql( $liefermenge * $mengenfaktor, $produkt_id, $bestell_id );
        }
      }
    }
    // Als nicht geliefert markierte Produkte löschen
    if( get_http_var( 'nichtGeliefert[]','u') ) {
      foreach( $nichtGeliefert as $p_id ) {
        nichtGeliefert( $bestell_id, $p_id );
      }
    }
  }

  //infos zur gesamtbestellung auslesen 
  $bestellung = sql_bestellung($bestell_id);

  ?><h1><? echo $title; ?></h1>

    <table width='100%' class='layout'>
      <tr>
        <td style='text-align:left;padding-bottom:1em;'>
         <?  bestellung_overview($bestellung,$gruppen_id,$gruppen_id); ?>
        </td>
        <td style='text-align:right;padding-bottom:1em;' id='option_menu'>
        </td>
      </tr>
    </table>
  <?

  option_menu_row( "<th colspan='2'>Anzeigeoptionen</th>" );

  option_menu_row(
    " <td>Gruppenansicht:</td>
      <td><select id='select_group' onchange=\"select_group('"
      . self_url( 'gruppen_id' ) . "');\">
    " . optionen_gruppen(
        $bestell_id
       , false
       , $gruppen_id
       , "Alle (Gesamtbestellung)"
      , ( $hat_dienst_IV ? false : $login_gruppen_id )
      , array( sql_basar_id() => 'Basar', sql_muell_id() => 'Müll' )
      ) . " </select></td>"
  );

  products_overview(
    $bestell_id,
    $editable,   // Liefermengen edieren zulassen?
    $editable,   // Preise edieren zulassen?
    $spalten,    // welche Tabellenspalten anzeigen
    $gruppen_id, // Gruppenansichte (0: alle)
    true,        // angezeigte Spalten auswaehlen lassen
    true,        // Gruppenansicht auswaehlen lassen
    true         // Option: Anzeige nichtgelieferte zulassen
  );

  switch( $state ) {
    case STATUS_LIEFERANT:
    case STATUS_VERTEILT:
      if( ! $readonly and ! $gruppen_id and ( $dienst == 1 || $dienst == 3 || $dienst == 4 ) ) {
        echo "
          <h3>Zusätzliches Produkt eintragen (wirkt wie Basar<b>bestellung</b>):</h3>
          <form method='post' action='" . self_url() . "'> " . self_post() . "
            <input type='hidden' name='action' value='insert'>
        ";
        select_products_not_in_list($bestell_id);
        echo "
          <label>Menge:</label>
          <input type='text' size='6' style='text-align:right;' name='menge' value='0'>
          <input type='submit' value='Produkt hinzufügen'>
          </form>
        ";
      }
      break;
    default:
      break;
  }
?>
