<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * German strings for local_ltixsembed.
 *
 * @package    local_ltixsembed
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['audit_authlti_disabled'] = 'Das Authentifizierungs-Plugin LTI ist nicht aktiviert; diese Website kann für eingebettete Starts keine Nutzer anlegen.';
$string['audit_chips_core_missing'] = 'Der Kern-Helper für partitionierte LTI-Cookies wurde nicht gefunden; Starts in Drittanbieter-Frames sind nicht unterstützbar.';
$string['audit_chips_core_ok'] = 'Der Kern liefert die SameSite=None; Secure; Partitioned-Behandlung, die LTI-Starts nutzen (Cookie-Helper von auth_lti).';
$string['audit_cookie_httponly_missing'] = 'Das Sitzungs-Cookie wird ohne HttpOnly gesendet.';
$string['audit_cookie_lax'] = 'Das Sitzungs-Cookie nutzt SameSite={$a}, wie Moodle es bei normalen Anfragen setzt. LTI-Starts erweitern es selbständig.';
$string['audit_cookie_none_global'] = 'Das Sitzungs-Cookie wird auf einer normalen Seite mit SameSite=None ({$a}) gesendet. Der Kern tut das nur bei LTI-Starts; etwas schreibt Sitzungs-Cookies websiteweit um. Ein pauschales SameSite=None schwächt den CSRF-Schutz.';
$string['audit_cookie_none_insecure'] = 'Das Sitzungs-Cookie wird mit SameSite=None, aber ohne Secure gesendet. Browser lehnen es ab; Proxy oder Plugin, das es umschreibt, korrigieren.';
$string['audit_cookie_not_seen'] = 'Die anonyme Antwort hat kein Sitzungs-Cookie gesetzt, daher konnten dessen Attribute nicht geprüft werden.';
$string['audit_cookiesecure_off'] = 'Die Website nutzt https, aber "Nur sichere Cookies verwenden" (cookiesecure) ist aus. Einschalten unter Website-Administration > Sicherheit > HTTP-Sicherheit.';
$string['audit_cookiesecure_ok'] = 'Sichere Cookies sind aktiviert (cookiesecure).';
$string['audit_csp_none'] = 'Es wird keine Content-Security-Policy-Direktive frame-ancestors gesendet.';
$string['audit_csp_none_blocks'] = 'Content-Security-Policy frame-ancestors ist \'none\': Keine Website darf diese einbetten.';
$string['audit_csp_ok'] = 'Content-Security-Policy frame-ancestors deckt die konfigurierten Origins ab: {$a}';
$string['audit_csp_partial'] = 'Content-Security-Policy frame-ancestors enthält diese konfigurierten Origins nicht: {$a}';
$string['audit_csp_wildcard'] = 'Content-Security-Policy frame-ancestors enthält *: Jede Website darf diese einbetten.';
$string['audit_facts'] = 'Wirksame Einstellungen';
$string['audit_fetch_blocked'] = 'Die Header-Selbstprüfung wurde nicht ausgeführt: Die eigene Adresse dieser Website ist durch die cURL-Sicherheit von Moodle gesperrt (Website-Administration > Allgemein > Sicherheit > HTTP-Sicherheit). Es wurde nichts abgerufen. Ist die Sperrliste so gewollt, lassen Sie sie unverändert; prüfen Sie die Header X-Frame-Options und Content-Security-Policy stattdessen in den Entwicklerwerkzeugen des Browsers.';
$string['audit_fetch_failed'] = 'Die Antwort-Header konnten nicht gelesen werden ({$a}). Die Header-Prüfungen entfallen.';
$string['audit_fetchnote'] = 'Die Header wurden mit einer anonymen Anfrage von der Login-Seite dieser Website gelesen. Es gelten die cURL-Sicherheitseinstellungen von Moodle; blockieren sie die Anfrage, entfallen die Header-Prüfungen.';
$string['audit_findings'] = 'Befunde';
$string['audit_framing_allowed'] = '"Einbetten in Frames erlauben" ist an: Seiten dieser Website dürfen in anderen Websites angezeigt werden. Auf einer Website, die als LTI-Tool dient, nötig.';
$string['audit_framing_blocked'] = '"Einbetten in Frames erlauben" ist aus: Diese Website kann nicht als eingebettetes LTI-Tool dienen. Nur auf einer Website einschalten, die eingebettet werden soll.';
$string['audit_httponly_off'] = 'Sitzungs-Cookies sind nicht HttpOnly (cookiehttponly). Skripte könnten sie lesen.';
$string['audit_httponly_ok'] = 'Sitzungs-Cookies sind HttpOnly.';
$string['audit_https_missing'] = 'Die Website-Adresse nutzt kein https. Einbettung über Websites hinweg braucht Secure-Cookies und damit https.';
$string['audit_https_ok'] = 'Die Website-Adresse nutzt https.';
$string['audit_intro'] = 'Schreibgeschützter Bericht über die Einstellungen und Antwort-Header, die entscheiden, ob diese Website in eine andere eingebettet werden oder eine andere einbetten kann. Hier wird nichts verändert.';
$string['audit_level_error'] = 'Problem';
$string['audit_level_info'] = 'Hinweis';
$string['audit_level_ok'] = 'In Ordnung';
$string['audit_level_warning'] = 'Warnung';
$string['audit_origins_none'] = 'Es sind keine vertrauenswürdigen Gegenstellen konfiguriert, daher bleibt die Nachrichtenbrücke inaktiv. Die Option "Neues Fenster" funktioniert weiterhin.';
$string['audit_title'] = 'Konfigurationsprüfung Einbettung';
$string['audit_xfo_absent'] = 'Die Antwort enthält keinen X-Frame-Options-Header.';
$string['audit_xfo_conflict'] = 'Die Antwort enthält X-Frame-Options "{$a}", obwohl "Einbetten in Frames erlauben" an ist. Ein Proxy oder Webserver überschreibt Moodle und verhindert die Einbettung.';
$string['audit_xfo_present'] = 'Die Antwort enthält X-Frame-Options "{$a}": Die Website lehnt es ab, von anderen Websites eingebettet zu werden.';
$string['cachedef_consumed'] = 'Verbrauchte Einmal-Token der Option für ein neues Fenster (nur Hashwerte)';
$string['cachedef_reports'] = 'Begrenzung der Problemmeldungen zur Einbettung (je Sitzung)';
$string['consumer_whattodo'] = 'Sie müssen keine Browsereinstellung ändern. Öffnen Sie das Tool mit der Schaltfläche unten in einem eigenen Fenster; Ihre Arbeit dort zählt wie gewohnt.';
$string['diag_feature'] = 'Prüfung';
$string['diag_intro'] = 'Diese Seite prüft in dem Browser, den Sie gerade verwenden, ob eine Seite eine Sitzung halten kann, wenn sie in einer anderen Website angezeigt wird. Direkt geöffnet zeigt sie das Top-Level-Ergebnis; das Ergebnis im Frame erscheint auf LTI-Seiten mit der Brücke.';
$string['diag_mode'] = 'Rolle dieser Website';
$string['diag_mode_help'] = 'Betrieb nur als Consumer, nur als Provider oder kooperativ ergibt sich aus diesen Schaltern: Eine Plattform aktiviert die Plattformseite, ein Tool die Toolseite, und zwei Websites arbeiten zusammen, wenn auf beiden die jeweilige Seite aktiviert ist. Jede Seite funktioniert allein; zusammen fügen sie nie eine zweite Weiterleitung, Anmeldung oder ein zweites Fenster hinzu.';
$string['diag_noscript'] = 'Für diesen Test ist JavaScript erforderlich.';
$string['diag_recommend_none'] = 'Keine Maßnahme nötig.';
$string['diag_recommend_open'] = 'Die Option "Neues Fenster" anbieten.';
$string['diag_recommendation'] = 'Empfehlung';
$string['diag_requestaccess'] = 'Speicherzugriff anfragen (Browser-Abfrage)';
$string['diag_requestaccess_help'] = 'Nur zur Diagnose. Der Weg über ein neues Fenster ist der unterstützte; das Plugin hängt nicht von dieser Abfrage ab.';
$string['diag_result'] = 'Ergebnis';
$string['diag_title'] = 'Browser-Fähigkeitstest';
$string['diag_value'] = 'Wert';
$string['error_nonce'] = 'Die Anforderung für ein neues Fenster ist nicht mehr gültig. Laden Sie die Aktivitätsseite neu und versuchen Sie es erneut.';
$string['error_origin_invalid'] = 'Kein gültiger Origin: {$a}. Verwenden Sie https://host oder https://host:port ohne Pfad.';
$string['error_origin_toomany'] = 'Höchstens {$a} Origins sind erlaubt.';
$string['error_postrequired'] = 'Diese Aktion muss als Formular (POST) gesendet werden.';
$string['event_embed_state_reported'] = 'Einbettungsproblem vom Browser gemeldet';
$string['event_fallback_launched'] = 'LTI-Aktivität in einem Top-Level-Fenster geöffnet';
$string['fallback_backtoactivity'] = 'Zurück zur Aktivität';
$string['fallbackmode_auto'] = 'Automatisch versuchen, Vorschlag als Rückfall behalten';
$string['fallbackmode_suggest'] = 'Nur vorschlagen';
$string['feat_cookie'] = 'Cookie schreiben und zurücklesen';
$string['feat_cookie_partitioned'] = 'Partitioniertes Cookie (CHIPS) Rundlauf';
$string['feat_framed'] = 'In einem Frame angezeigt';
$string['feat_hassa'] = 'Storage Access API: document.hasStorageAccess()';
$string['feat_localstorage'] = 'Local Storage nutzbar';
$string['feat_platformstorage'] = 'LTI Platform Storage (postMessage)';
$string['feat_requestsa'] = 'Storage Access API: document.requestStorageAccess()';
$string['feat_session'] = 'Sitzungshinweis';
$string['ltixsembed:viewdiagnostics'] = 'Diagnose der LTI-Einbettung und Konfigurationsprüfung ansehen';
$string['manualfallback_button'] = 'Tool in neuem Fenster öffnen';
$string['manualfallback_help'] = 'Das Tool öffnet sich in einem neuen Fenster und startet dort seine gewohnte sichere Anmeldung. Sie müssen keine Browsereinstellung ändern.';
$string['manualfallback_summary'] = 'Probleme beim Laden des Tools? In neuem Fenster öffnen';
$string['mode_both'] = 'Plattformseite und Toolseite aktiviert';
$string['mode_consumer'] = 'Nur Plattformseite (nur Consumer)';
$string['mode_inactive'] = 'Inaktiv: Es wird keiner Seite etwas hinzugefügt';
$string['mode_provider'] = 'Nur Toolseite (nur Provider)';
$string['page_audit'] = 'Konfigurationsprüfung Einbettung';
$string['page_diagnostics'] = 'Browser-Fähigkeitstest';
$string['peerbridge_auto'] = 'Nicht erforderlich: Ein stummes Tool ist normal';
$string['peerbridge_required'] = 'Erforderlich: Ein stummes Tool bedeutet einen blockierten Frame';
$string['pluginname'] = 'LTI Cross-Site Embed';
$string['privacy:metadata'] = 'Das Plugin LTI Cross-Site Embed speichert keine personenbezogenen Daten in eigenem Speicher. Seine Browserprüfungen laufen in der Seite; Einmal-Token werden nur als Hashwert ohne Nutzerbezug aufbewahrt. Kann ein eingebettetes Tool nicht genutzt werden, löst das Plugin ein Ereignis aus, das die Protokollspeicher der Website festhalten (Nutzer, ein Statuswort und eine zufällige Versuchsnummer); diese Protokollspeicher exportieren und löschen es.';

$string['privacynote'] = 'Diese Prüfung läuft in Ihrem Browser. Zwischen dieser Seite und dem eingebetteten Tool wird nur ein kurzes Statuswort ausgetauscht. Kann das Tool hier nicht genutzt werden, werden dieses Statuswort und eine zufällige Versuchsnummer im Website-Protokoll vermerkt, damit der Support nachvollziehen kann, was passiert ist; über Ihren Browser oder Ihr Surfverhalten wird nichts gespeichert.';
$string['provider_hint'] = 'Bleibt diese Seite leer oder meldet sie ein Problem, gehen Sie zurück in Ihren Kurs und öffnen Sie die Aktivität erneut; bietet Ihre Kursseite "Tool in neuem Fenster öffnen" an, nutzen Sie diese Option.';
$string['provider_hint_coop'] = 'Die Seite, die dieses Tool enthält, kennt das Problem und bietet an, das Tool in einem eigenen Fenster zu öffnen.';
$string['setting_allowedorigins'] = 'Vertrauenswürdige Gegenstellen (Origins)';
$string['setting_allowedorigins_desc'] = 'Ein Origin pro Zeile, zum Beispiel <code>https://tool.example.org</code>. Auf einer Plattform sind das die Origins der eingebetteten Tools, auf einem Tool die Origins der einbettenden Plattformen. Nur exakte Übereinstimmung: keine Platzhalter, keine Pfade, nur https (http nur auf Entwicklungsseiten, die selbst http nutzen). Nachrichten von allen anderen Origins werden ignoriert.';
$string['setting_consumerenabled'] = 'Plattformseite (mod_lti-Ansicht)';
$string['setting_consumerenabled_desc'] = 'Brücke und Option "In neuem Fenster öffnen" auf LTI-Aktivitätsseiten ergänzen, die das Tool in einem iframe zeigen.';
$string['setting_fallbackmode'] = 'Verhalten des Fallbacks';
$string['setting_fallbackmode_desc'] = 'Vorschlagen: Hinweis mit Schaltfläche anzeigen. Automatisch: zusätzlich sofort versuchen, das neue Fenster zu öffnen; Browser können das blockieren, der Hinweis bleibt als Rückfall.';
$string['setting_handshaketimeout'] = 'Wartezeit auf das Tool (Sekunden)';
$string['setting_handshaketimeout_desc'] = 'Wie lange die Plattformseite auf eine Antwort des eingebetteten Tools wartet, bevor sie die Option "Neues Fenster" vorschlägt.';
$string['setting_noncelifetime'] = 'Gültigkeit des Einmal-Tokens (Sekunden)';
$string['setting_noncelifetime_desc'] = 'Wie lange das Token gültig bleibt, das den Fenster-Fallback autorisiert. Zwischen 30 und 3600.';
$string['setting_peerbridge'] = 'Brücke auf der Toolseite';
$string['setting_peerbridge_desc'] = 'Nur Plattformseite. Nicht erforderlich (Standard): Das eingebettete Tool nutzt dieses Plugin womöglich nicht oder bleibt nach einem erfolgreichen Launch stumm; die Plattform zieht daraus keinen Schluss und schlägt nichts vor, die manuelle Option für ein neues Fenster bleibt verfügbar (Betrieb nur als Consumer). Erforderlich: Nur wählen, wenn jedes unter den vertrauenswürdigen Gegenstellen eingetragene Tool dieses Plugin mit eingeschalteter Option "Toolseite: Seiten von LTI-Nutzern" betreibt; dann wird Stille bis zum Zeitlimit als blockierter Frame gemeldet.';
$string['setting_providerenabled'] = 'Toolseite (LTI-Startseiten)';
$string['setting_providerenabled_desc'] = 'Brücke auf den Seiten des LTI-Startablaufs (auth_lti, enrol_lti) ergänzen, wenn sie in einem Frame angezeigt werden.';
$string['setting_providerltipages'] = 'Toolseite: Seiten von LTI-Nutzern';
$string['setting_providerltipages_desc'] = 'Brücke auch auf normalen Seiten von Nutzern ergänzen, die sich über LTI angemeldet haben. Standardmäßig aus; einschalten, um nach erfolgreichem Start den Zustand "Eingebettet und funktionsfähig" zu sehen.';
$string['setting_storageaccess'] = 'Berechtigungsschaltfläche anbieten (Storage Access API)';
$string['setting_storageaccess_desc'] = 'Wo der Browser es unterstützt und das eingebettete Tool keine Sitzung halten kann, zeigt das Tool eine Schaltfläche. Der Browser fragt die Nutzerin oder den Nutzer um Erlaubnis; nach einer Erteilung startet die Plattform den Launch neu. Es wird nichts abgeschaltet; Browser ohne diese API erhalten nur die Option des neuen Fensters.';
$string['settings_heading'] = 'Einstellungen LTI Cross-Site Embed';
$string['settings_intro'] = 'Steuert, wo die Brücke zwischen einer einbettenden Plattform und einem eingebetteten Tool aktiv ist. Das Plugin ändert keine Moodle-Sicherheitseinstellungen und fordert niemanden auf, den Browserschutz zu ändern.';

$string['state_blocked_frame'] = 'Frame blockiert oder Tool antwortet nicht';
$string['state_blocked_frame_msg'] = 'Das eingebettete Tool hat nicht geantwortet. Meist verweigert das Tool die Anzeige innerhalb einer anderen Website (eine Sicherheitseinstellung des Tools), oder ein Netzwerkproblem hat es verhindert.';
$string['state_embedded'] = 'Eingebettet und funktionsfähig';
$string['state_needs_user_intervention'] = 'Erlaubnis des Nutzers nötig';
$string['state_needs_user_intervention_msg'] = 'Ihr Browser trennt eingebettete Websites voneinander. Das Tool kann Sie innerhalb dieser Seite nur angemeldet halten, wenn Sie es im Toolbereich unten erlauben.';
$string['state_no_peer'] = 'Tool ohne Brücke (normal, wenn das Tool dieses Plugin nicht nutzt)';
$string['state_session_lost'] = 'Sitzung verloren';
$string['state_session_lost_msg'] = 'Das eingebettete Tool hat Sie abgemeldet, zum Beispiel weil seine Sitzung abgelaufen ist.';
$string['state_storage_unavailable'] = 'Speicher nicht verfügbar';
$string['state_storage_unavailable_msg'] = 'Ihr Browser erlaubt eingebetteten Websites nicht, Sie angemeldet zu halten (Cookies eingebetteter Websites sind blockiert). Das schützt Ihre Privatsphäre und muss nicht geändert werden.';

$string['state_top_level'] = 'Top-Level-Fenster';
$string['storageaccess_button'] = 'Diesem Tool erlauben, Ihre Sitzung zu halten';
$string['storageaccess_denied'] = 'Ihr Browser hat die Erlaubnis nicht erteilt. Nutzen Sie stattdessen die Option, das Tool in einem neuen Fenster zu öffnen.';
$string['storageaccess_granted'] = 'Erlaubnis erteilt. Das Tool wird neu gestartet.';
$string['storageaccess_granted_standalone'] = 'Erlaubnis erteilt. Gehen Sie zurück in Ihren Kurs und öffnen Sie die Aktivität erneut.';
$string['storageaccess_help'] = 'Ihr Browser fragt um Erlaubnis. Sie wird nur genutzt, damit dieses Tool Sie innerhalb dieser Seite angemeldet halten kann; kein Browserschutz wird abgeschaltet.';

$string['value_available'] = 'verfügbar';
$string['value_lost'] = 'verloren';
$string['value_na'] = 'nicht anwendbar';
$string['value_no'] = 'nein';
$string['value_ok'] = 'in Ordnung';
$string['value_pending'] = 'wird geprüft ...';
$string['value_unavailable'] = 'nicht verfügbar';
$string['value_unknown'] = 'unbekannt';
$string['value_yes'] = 'ja';
