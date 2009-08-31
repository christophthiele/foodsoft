<?php
//
// verluste.php: detailansichten und uebersichtstabelle
//

assert( $angemeldet ) or exit();

setWikiHelpTopic( 'foodsoft:verluste' );
setWindowSubtitle( 'Verluste' );

$editable = ( hat_dienst(4) and ! $readonly );
// get_http_var( 'optionen', 'u', 0, true );

get_http_var( 'detail', 'w', 0, true );

$muell_id = sql_muell_id();


function verlust_bestellungen( $detail = false ) {
  global $muell_id;
  if( $detail ) {
    ?> <h2>Differenzen aus Bestellungen:</h2> <?
    open_table( 'list', "width='98%'" );
      open_th('','','Bestellung');
      open_th('','','Schwund/Müll');
      open_th('',"colspan='2'",'Sonstiges');
      open_th('','','Haben FC');
  }

  $result = doSql( "
    SELECT gesamtbestellungen.*
    , (" .select_bestellungen_soll_gruppen( OPTION_ENDPREIS_SOLL, array( 'gesamtbestellungen', 'bestellgruppen' ) ). ") as muell_soll
    FROM gesamtbestellungen
    JOIN bestellgruppen ON bestellgruppen.id = $muell_id
    HAVING ( extra_soll <> 0 ) OR ( muell_soll <> 0)
    ORDER BY gesamtbestellungen.lieferung
  " );

  $muell_soll_summe = 0;
  $extra_soll_summe = 0;
  $soll_summe = 0;

  while( $row = mysql_fetch_array( $result ) ) {
    $muell_soll = - $row['muell_soll'];  // soll _aus_sicht_von_gruppe_13_! (also der FC-Gemeinschaft!)
    $extra_soll = $row['extra_soll'];
    $soll = $muell_soll + $extra_soll;
    $bestell_id = $row['id'];

    if( $detail ) {
      open_tr();
        open_td( '', '', fc_link( 'abrechnung', array( 'class' => 'href', 'bestell_id' => $bestell_id, 'text' => $row['name'] ) ) );
        open_td( 'number', '', fc_link( 'lieferschein'
                    , "class=href,bestell_id=$bestell_id,gruppen_id=$muell_id,text=". sprintf( "%.2lf", - $muell_soll ) ) );
        open_td( '', '',  $row['extra_text'] );
        open_td( 'number', '', price_view( - $extra_soll ) );
        open_td( 'number', '', price_view( - $soll ) );
    }
    $muell_soll_summe += $muell_soll;
    $extra_soll_summe += $extra_soll;
    $soll_summe += $soll;
  }
  if( $detail ) {
    open_tr('summe');
      open_td('','','Summe:');
      open_td( 'number', '', price_view( - $muell_soll_summe ) );
      open_td();
      open_td( 'number', '', price_view( - $extra_soll_summe ) );
      open_td( 'number', '', price_view( - $soll_summe ) );
    close_table();
  }

  return $soll_summe;
}

function verlust_transaktionen( $typ, $detail = false ) {
  global $option_flag, $optionen, $verluste_summe;
  if( $detail ) {
    echo "<h4>". transaktion_typ_string( $typ ) ."</h4>";
    open_table( 'list', "width='98%'" );
      open_th('','','Id');
      open_th('','','Valuta');
      open_th('','','Notiz');
      open_th('oneline','','Haben FC');
  }

  $soll_summe = 0.0;
  foreach( sql_verluste( $typ ) as $row ) {
    $soll = - $row['soll'];  // switch from bookkeeper's to shareholder's POV
    if( $detail ) {
      open_tr();
        open_td( '', '', fc_link( 'edit_buchung', "transaktion_id={$row['id']},class=href,text={$row['id']}" ) );
        open_td( '', '', $row['valuta'] );
        open_td( '', '', $row['notiz'] );
        open_td( 'number', '', price_view( - $soll ) );
    }
    $soll_summe += $soll;
  }
  if( $detail ) {
    open_tr('summe');
      open_td( 'right', "colspan='3'", 'Summe:' );
      open_td( 'number', '', price_view( - $soll_summe ) );
    close_table();
  }
  return $soll_summe;
}

get_http_var( 'action', 'w', '' );
$editable or $action = '';
switch( $action ) {
  case 'umbuchung_verlust':
    action_umbuchung_verlust();
    break;
  case 'gruppen_umlage':
    action_gruppen_umlage();
    break;
}

if( $detail ) {
  if( $detail == 'bestellungen' ) {
    verlust_bestellungen( true );
  } else if ( $detail == 'undefiniert' ) {
    verlust_transaktionen( TRANSAKTION_TYP_UNDEFINIERT, true );
  } else {
    verlust_transaktionen( $detail, true );
  }
  return;
}

$verluste_summe = 0.0;
$ausgleich_summe = 0.0;

?> <h1>Verlustaufstellung --- &Uuml;bersicht</h1> <?

if( $editable ) {

  open_fieldset( 'small_form', '', 'Transaktionen', 'off' );
    ?> <h4>Art der Transaktion:</h4> <?
    alternatives_radio( array(
      'umbuchung_form' => array( 'Umbuchung Verlustausgleich'
                               , 'Umbuchung von Spenden oder Umlagen zur Schuldentilgung' )
    , 'umlage_form' => array( 'Umlage erheben'
                            , 'Umlage von allen(!) aktiven Gruppenmitgliedern erheben' )
    ) );
    open_div( 'nodisplay', "id='umbuchung_form'" );
      formular_umbuchung_verlust();
    close_div();
    open_div( 'nodisplay', "id='umlage_form'" );
      formular_gruppen_umlage();
    close_div();
  close_fieldset();
  medskip();
}

//
// verluste --- uebersichtstabelle
//
open_table('list');
  open_th('','',       'Typ');
  open_th('oneline','','Haben FC');
  open_th('','',       'Ausgleichsbuchungen');
  open_th('','',       'Stand');

open_tr();
  open_td( '', '', "Altlasten (Anfangsguthaben):" );

  $soll = verlust_transaktionen( TRANSAKTION_TYP_ANFANGSGUTHABEN );
  $verluste_summe += $soll;

  $ausgleich = verlust_transaktionen( TRANSAKTION_TYP_AUSGLEICH_ANFANGSGUTHABEN );
  $ausgleich_summe += $ausgleich;

  open_td( 'number','', fc_link( 'verlust_details'
    , array( 'detail' => TRANSAKTION_TYP_ANFANGSGUTHABEN, 'class' => 'href', 'text' => price_view( -$soll ) ) ) );
  open_td( 'number','', fc_link( 'verlust_details'
    , array( 'detail' => TRANSAKTION_TYP_AUSGLEICH_ANFANGSGUTHABEN, 'class' => 'href', 'text' => price_view( -$ausgleich ) ) ) );
  open_td( 'number', '', price_view( - $soll - $ausgleich ) );


open_tr();
  open_td( '','', 'Verluste aus Bestellungen:' );

  $soll = verlust_bestellungen( false );
  $verluste_summe += $soll;

  $ausgleich = verlust_transaktionen( TRANSAKTION_TYP_AUSGLEICH_BESTELLVERLUSTE );
  $ausgleich_summe += $ausgleich;

  open_td( 'number', '', fc_link( 'verlust_details'
    , array( 'detail' => 'bestellungen', 'class' => 'href' , 'text' => price_view( -$soll ) ) ) );
  open_td( 'number', '', fc_link( 'verlust_details'
    , array( 'detail' => TRANSAKTION_TYP_AUSGLEICH_BESTELLVERLUSTE, 'class' => 'href', 'text' => price_view( -$ausgleich ) ) ) );
  open_td( 'number', '', price_view( - $soll - $ausgleich ) );


open_tr();
  open_td( '','', 'Sonderausgaben:' );

  $soll = verlust_transaktionen( TRANSAKTION_TYP_SONDERAUSGABEN );
  $verluste_summe += $soll;

  $ausgleich = verlust_transaktionen( TRANSAKTION_TYP_AUSGLEICH_SONDERAUSGABEN );
  $ausgleich_summe += $ausgleich;

  open_td( 'number', '', fc_link( 'verlust_details'
    , array( 'detail' => TRANSAKTION_TYP_SONDERAUSGABEN, 'class' => 'href' , 'text' => price_view( -$soll ) ) ) );
  open_td( 'number', '', fc_link( 'verlust_details'
    , array( 'detail' => TRANSAKTION_TYP_AUSGLEICH_SONDERAUSGABEN, 'class' => 'href' , 'text' => price_view( -$ausgleich ) ) ) );
  open_td( 'number', '', price_view( - $soll - $ausgleich ) );


open_tr( 'summe' );
  open_td( '', '', 'Zwischensumme:' );
    open_td( 'number', '', price_view( - $verluste_summe ) );
    open_td( 'number', '', price_view( - $ausgleich_summe ) );
    open_td( 'number', '', price_view( - $ausgleich_summe - $verluste_summe ) );


open_tr();
  open_th( '', "colspan='4' style='padding-top:1em;'", 'Einnahmen:' );

open_tr();
  open_td( '', '', 'Spenden:' );

  $soll = verlust_transaktionen( TRANSAKTION_TYP_SPENDE );
  $verluste_summe += $soll;

  $ausgleich = verlust_transaktionen( TRANSAKTION_TYP_UMBUCHUNG_SPENDE );
  $ausgleich_summe += $ausgleich;

  open_td( 'number', '', fc_link( 'verlust_details'
    , array( 'detail' => TRANSAKTION_TYP_SPENDE, 'class' => 'href' , 'text' => price_view( -$soll ) ) ) );
  open_td( 'number', '', fc_link( 'verlust_details'
    , array( 'detail' => TRANSAKTION_TYP_UMBUCHUNG_SPENDE, 'class' => 'href' , 'text' => price_view( -$ausgleich ) ) ) );
  open_td( 'number', '', price_view( - $soll - $ausgleich ) );


open_tr();
  open_td( '', '', 'Umlagen:' );

  $soll = verlust_transaktionen( TRANSAKTION_TYP_UMLAGE );
  $verluste_summe += $soll;

  $ausgleich = verlust_transaktionen( TRANSAKTION_TYP_UMBUCHUNG_UMLAGE );
  $ausgleich_summe += $ausgleich;

  open_td( 'number', '', fc_link( 'verlust_details'
    , array( 'detail' => TRANSAKTION_TYP_UMLAGE, 'class' => 'href' , 'text' => price_view( -$soll ) ) ) );
  open_td( 'number', '', fc_link( 'verlust_details'
    , array( 'detail' => TRANSAKTION_TYP_UMBUCHUNG_UMLAGE, 'class' => 'href' , 'text' => price_view( -$ausgleich ) ) ) );
  open_td( 'number', '', price_view( - $soll - $ausgleich ) );


open_tr();
  open_th( '', "colspan='4' style='padding-top:1em;'", 'Sonstiges:' );

open_tr();
  open_td( '', '', 'nicht klassifiziert:' );

  $soll = verlust_transaktionen( TRANSAKTION_TYP_UNDEFINIERT );
  $verluste_summe += $soll;

  open_td( 'number', '', fc_link( 'verlust_details'
    , array( 'detail' => 'undefiniert', 'class' => 'href' , 'text' => price_view( -$soll ) ) ) );
  open_td();
  open_td();

open_tr('summe');
  open_td( '', '', 'Summe:' );
  open_td( 'number', '', price_view( - $verluste_summe ) );
  open_td( 'number', '', price_view( - $ausgleich_summe ) );
  open_td( 'number', '', price_view( - $ausgleich_summe - $verluste_summe ) );


open_tr();
  open_th( '', "colspan='4' style='padding-top:1em;'", 'Weitere "Muell"-Buchungen (keine Verluste):' );

open_tr();
  open_td( '', "colspan='3'", 'Stornos (sollten zusammen Betrag 0 ergeben):' );
  open_td( 'number', '', fc_link( 'verlust_details', array( 'detail' => TRANSAKTION_TYP_STORNO
        , 'class' => 'href', 'text' => price_view( -verlust_transaktionen( TRANSAKTION_TYP_STORNO ) ) ) ) );

open_tr();
  open_td( '', "colspan='3'", '"geparkte" Sockeleinlagen:' );
  open_td( 'number', '', fc_link( 'verlust_details', array( 'detail' => TRANSAKTION_TYP_SOCKEL
        , 'class' => 'href', 'text' => price_view( -verlust_transaktionen( TRANSAKTION_TYP_SOCKEL ) ) ) ) );

close_table();

?>
