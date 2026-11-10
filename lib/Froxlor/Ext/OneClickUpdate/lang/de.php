<?php

/**
 * German user interface of One-Click Update: English source text => German.
 * Checked by tests/lang.php (every text has an entry, placeholders match).
 */

return [
	// Menu and page
	'Froxlor update' => 'Froxlor-Update',
	'new' => 'neu',
	'Bring Froxlor up to date with one click — with a snapshot taken first.' => 'Froxlor mit einem Klick auf den neuesten Stand bringen – vorher wird eine Sicherung angelegt.',
	'Check again' => 'Erneut prüfen',
	'Close' => 'Schließen',

	// Version status
	'Froxlor %s is available.' => 'Froxlor %s ist verfügbar.',
	'Froxlor is up to date.' => 'Froxlor ist auf dem neuesten Stand.',
	'Installed: %s' => 'Installiert: %s',
	'Channel: %s' => 'Kanal: %s',
	'stable' => 'stabil',
	'testing' => 'Testversionen',
	'Release notes' => 'Versionshinweise',
	'Update now' => 'Jetzt aktualisieren',
	'The version check failed.' => 'Die Versionsprüfung ist fehlgeschlagen.',
	'The version check failed: %s' => 'Die Versionsprüfung ist fehlgeschlagen: %s',
	'The version server sent an invalid version number.' => 'Der Versionsserver hat eine ungültige Versionsnummer geschickt.',
	'First the server takes a snapshot of Froxlor\'s files and its database; the last %d are kept.' => 'Zuerst sichert der Server Froxlors Dateien und Datenbank; die letzten %d Sicherungen bleiben erhalten.',
	'Snapshots are switched off on this server (SNAPSHOTS=0).' => 'Sicherungen sind auf diesem Server abgeschaltet (SNAPSHOTS=0).',
	'Websites, email and customer databases keep running. The update usually takes one to two minutes.' => 'Websites, E-Mail und Kundendatenbanken laufen weiter. Das Update dauert meist ein bis zwei Minuten.',
	'Afterwards the installed extensions are set up again (%d).' => 'Danach werden die installierten Erweiterungen neu eingerichtet (%d).',

	// Service missing / stuck
	'The update service is not installed on this server.' => 'Der Update-Dienst ist auf diesem Server nicht eingerichtet.',
	'The panel cannot update Froxlor by itself — that needs root. Run install.sh of One-Click Update as root once; it sets up a small service that does the update when you click the button.' => 'Das Panel kann Froxlor nicht selbst aktualisieren – dafür braucht es root. Führen Sie install.sh von One-Click Update einmal als root aus; es richtet einen kleinen Dienst ein, der das Update beim Klick auf den Knopf ausführt.',
	'The update service is not installed on this server. Run install.sh as root first.' => 'Der Update-Dienst ist auf diesem Server nicht eingerichtet. Führen Sie zuerst install.sh als root aus.',
	'The last run started at %s and never reported back. It was probably interrupted; the log below shows how far it got.' => 'Der letzte Lauf hat am %s begonnen und sich nie zurückgemeldet. Er wurde wahrscheinlich unterbrochen; das Protokoll unten zeigt, wie weit er kam.',
	'The request directory %s is not writable for the panel. Run install.sh again.' => 'Das Auftragsverzeichnis %s ist für das Panel nicht beschreibbar. Führen Sie install.sh erneut aus.',
	'Could not write %s.' => '%s konnte nicht geschrieben werden.',

	// Starting
	'An update is already running.' => 'Es läuft bereits ein Update.',
	'Froxlor is already up to date.' => 'Froxlor ist bereits auf dem neuesten Stand.',
	'Update to %s requested. It starts within a few seconds.' => 'Update auf %s angefordert. Es beginnt in wenigen Sekunden.',

	// Progress
	'Update requested — starting …' => 'Update angefordert – startet …',
	'Froxlor is being updated' => 'Froxlor wird aktualisiert',
	'From %s to %s' => 'Von %s auf %s',
	'Check for a newer version' => 'Nach neuerer Version suchen',
	'Wait for Froxlor\'s cron jobs' => 'Auf Froxlors Cronjobs warten',
	'Take a snapshot of files and database' => 'Dateien und Datenbank sichern',
	'Install the new version' => 'Neue Version installieren',
	'Check files and database' => 'Dateien und Datenbank prüfen',
	'Reinstall extensions' => 'Erweiterungen neu einrichten',
	'This page refreshes itself. You can also close it: the update keeps running on the server.' => 'Diese Seite aktualisiert sich selbst. Sie können sie auch schließen: Das Update läuft auf dem Server weiter.',
	'Refresh now' => 'Seite neu laden',
	'Log' => 'Protokoll',

	// Last run
	'Last run' => 'Letzter Lauf',
	'Started' => 'Begonnen',
	'took %s' => 'Dauer %s',
	'%d s' => '%d s',
	'%d min %d s' => '%d min %d s',
	'Requested by' => 'Angefordert von',
	'Method' => 'Verfahren',
	'Debian package (apt)' => 'Debian-Paket (apt)',
	'Archive from froxlor.org (froxlor-cli froxlor:update)' => 'Archiv von froxlor.org (froxlor-cli froxlor:update)',
	'Snapshot' => 'Sicherung',
	'To go back, run as root on the server:' => 'Zurück auf diesen Stand: als root auf dem Server ausführen:',
	'Show log' => 'Protokoll anzeigen',

	// Results reported by the service
	'Froxlor was updated from %s to %s.' => 'Froxlor wurde von %s auf %s aktualisiert.',
	'Froxlor was updated from %s to %s, but the installer of at least one extension failed. Run it again by hand: %s' => 'Froxlor wurde von %s auf %s aktualisiert, aber das Installationsskript mindestens einer Erweiterung ist fehlgeschlagen. Bitte von Hand erneut ausführen: %s',
	'The pending database update of Froxlor %s was completed.' => 'Das ausstehende Datenbank-Update von Froxlor %s wurde abgeschlossen.',
	'Froxlor was put back to snapshot %s.' => 'Froxlor wurde auf die Sicherung %s zurückgesetzt.',
	'The update command failed and the version did not change, but some files may have been replaced. Check the log; the snapshot can bring Froxlor back.' => 'Der Update-Befehl ist fehlgeschlagen und die Version hat sich nicht geändert, einzelne Dateien können aber schon ersetzt sein. Prüfen Sie das Protokoll; mit der Sicherung lässt sich Froxlor zurückholen.',
	'There is not enough disk space for the update and the snapshot. Nothing was changed.' => 'Für Update und Sicherung ist nicht genug Speicherplatz frei. Es wurde nichts geändert.',
	'The update service could not start: %s' => 'Der Update-Dienst konnte nicht starten: %s',
	'The update request was not picked up.' => 'Der Update-Auftrag wurde nicht übernommen.',
	'The service on the server did not start. As root, check it with: systemctl status froxlor-one-click-update.path froxlor-one-click-update.service — then request the update again.' => 'Der Dienst auf dem Server ist nicht gestartet. Prüfen Sie als root mit: systemctl status froxlor-one-click-update.path froxlor-one-click-update.service – und fordern Sie das Update dann erneut an.',
	'Froxlor was already up to date, nothing was changed.' => 'Froxlor war bereits aktuell, es wurde nichts geändert.',
	'The version check failed — the version server may be unreachable. Nothing was changed.' => 'Die Versionsprüfung ist fehlgeschlagen – der Versionsserver ist vielleicht nicht erreichbar. Es wurde nichts geändert.',
	'The installed version could not be read. Nothing was changed.' => 'Die installierte Version ließ sich nicht lesen. Es wurde nichts geändert.',
	'A Froxlor cron job did not finish in time. Nothing was changed — try again later.' => 'Ein Froxlor-Cronjob wurde nicht rechtzeitig fertig. Es wurde nichts geändert – bitte später erneut versuchen.',
	'The snapshot could not be taken, so the update did not start. Nothing was changed.' => 'Die Sicherung ließ sich nicht anlegen, deshalb hat das Update nicht begonnen. Es wurde nichts geändert.',
	'The package source does not offer a newer froxlor package yet. Nothing was changed — try again later.' => 'Die Paketquelle bietet noch kein neueres froxlor-Paket an. Es wurde nichts geändert – bitte später erneut versuchen.',
	'The files were updated, but the database update did not complete. Open Froxlor\'s update page or run the update again.' => 'Die Dateien wurden aktualisiert, das Datenbank-Update ist aber nicht fertig geworden. Öffnen Sie Froxlors Update-Seite oder starten Sie das Update erneut.',
	'The update did not change the installed version. Details are in the log.' => 'Das Update hat die installierte Version nicht verändert. Details stehen im Protokoll.',
	'The update was interrupted (%s). Check the log; the snapshot can bring Froxlor back.' => 'Das Update wurde unterbrochen (%s). Prüfen Sie das Protokoll; mit der Sicherung lässt sich Froxlor zurückholen.',
	'The update failed (%s).' => 'Das Update ist fehlgeschlagen (%s).',

	// How it works
	'How it works' => 'So funktioniert es',
	'The button only leaves a request. A service on the server picks it up within seconds and runs the update as root — the same steps an administrator would type in the shell.' => 'Der Knopf hinterlegt nur einen Auftrag. Ein Dienst auf dem Server übernimmt ihn binnen Sekunden und führt das Update als root aus – dieselben Schritte, die ein Administrator in der Shell eingeben würde.',
	'Snapshots kept' => 'Aufbewahrte Sicherungen',
	'none' => 'keine',
	'Extensions set up again afterwards' => 'Danach neu eingerichtete Erweiterungen',
	'On the server (as root):' => 'Auf dem Server (als root):',

	// Errors
	'The page could not be displayed. Details are in the PHP error log.' => 'Die Seite konnte nicht angezeigt werden. Details stehen im PHP-Fehlerprotokoll.',
];
