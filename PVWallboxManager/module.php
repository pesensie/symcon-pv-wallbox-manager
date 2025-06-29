<?php
class PVWallboxManager extends IPSModule
{
    public function Create()
    {
        parent::Create();

        // Visualisierung berechneter Werte
        $this->RegisterVariableFloat('PV_Ueberschuss', 'PV-Überschuss (W)', '~Watt', 10); // Aktuell berechneter PV-Überschuss in Watt

        // Energiequellen (Variablen-IDs für Berechnung)
        $this->RegisterPropertyInteger('PVErzeugungID', 0); // PV-Erzeugung in Watt
        $this->RegisterPropertyString("PVErzeugungEinheit", "W");
        
        $this->RegisterPropertyInteger('HausverbrauchID', 0); // Hausverbrauch in Watt
        $this->RegisterPropertyBoolean("InvertHausverbrauch", false);
        $this->RegisterPropertyString("HausverbrauchEinheit", "W");
        
        $this->RegisterPropertyInteger('BatterieladungID', 0); // Batterie-Lade-/Entladeleistung in Watt
        $this->RegisterPropertyBoolean("InvertBatterieladung", false);
        $this->RegisterPropertyString("BatterieladungEinheit", "W");
        
        $this->RegisterPropertyInteger('NetzeinspeisungID', 0); // Einspeisung/Bezug ins Netz (positiv/negativ)
        $this->RegisterPropertyBoolean("InvertNetzeinspeisung", false);
        $this->RegisterPropertyString("NetzeinspeisungEinheit", "W");

        // Wallbox-Einstellungen
        $this->RegisterPropertyInteger('GOEChargerID', 0); // Instanz-ID des GO-e Chargers
        $this->RegisterPropertyInteger('MinAmpere', 6); // Minimale Ladeleistung (Ampere)
        $this->RegisterPropertyInteger('MaxAmpere', 16); // Maximale Ladeleistung (Ampere)
        $this->RegisterPropertyInteger('Phasen', 3); // Anzahl aktiv verwendeter Ladephasen (1 oder 3)

        // Lade-Logik & Schwellenwerte
        $this->RegisterPropertyInteger('MinLadeWatt', 1400); // Mindest-PV-Überschuss zum Starten (Watt)
        $this->RegisterPropertyInteger('MinStopWatt', -300); // Schwelle zum Stoppen bei Defizit (Watt)
        $this->RegisterPropertyInteger('Phasen1Schwelle', 1000); // Schwelle zum Umschalten auf 1-phasig (Watt)
        $this->RegisterPropertyInteger('Phasen3Schwelle', 4200); // Schwelle zum Umschalten auf 3-phasig (Watt)
        $this->RegisterPropertyInteger('Phasen1Limit', 3); // Messzyklen unterhalb Schwelle vor Umschalten auf 1-phasig
        $this->RegisterPropertyInteger('Phasen3Limit', 3); // Messzyklen oberhalb Schwelle vor Umschalten auf 3-phasig
        $this->RegisterPropertyBoolean('DynamischerPufferAktiv', true); // Dynamischer Sicherheitsabzug aktiv

        // Fahrzeug-Erkennung & Ziel-SOC
        $this->RegisterPropertyBoolean('NurMitFahrzeug', true); // Ladung nur wenn Fahrzeug verbunden
        $this->RegisterPropertyBoolean('UseCarSOC', false); // Fahrzeug-SOC berücksichtigen
        $this->RegisterPropertyInteger('CarSOCID', 0); // Variable für aktuellen SOC des Fahrzeugs
        $this->RegisterPropertyFloat('CarSOCFallback', 20); // Fallback-SOC wenn keine Variable verfügbar
        $this->RegisterPropertyInteger('CarTargetSOCID', 0); // Ziel-SOC Variable
        $this->RegisterPropertyFloat('CarTargetSOCFallback', 80); // Fallback-Zielwert für SOC
        $this->RegisterPropertyFloat('CarBatteryCapacity', 52.0); // Batteriekapazität des Fahrzeugs in kWh
        $this->RegisterPropertyBoolean('AlwaysUseTargetSOC', false); // Ziel-SOC immer berücksichtigen (auch bei PV-Überschussladung)

        // Interne Status-Zähler für Phasenumschaltung
        $this->RegisterAttributeInteger('Phasen1Counter', 0);
        $this->RegisterAttributeInteger('Phasen3Counter', 0);

        $this->RegisterAttributeBoolean('RunLogFlag', true);

        // Start/Stop-Hysterese
        $this->RegisterPropertyInteger('StartHysterese', 0); // Anzahl Zyklen über Startschwelle bis gestartet wird
        $this->RegisterPropertyInteger('StopHysterese', 0);  // Anzahl Zyklen unter Stoppschwelle bis gestoppt wird

        $this->RegisterAttributeInteger('StartHystereseCounter', 0);
        $this->RegisterAttributeInteger('StopHystereseCounter', 0);

        // Erweiterte Logik: PV-Verteilung Auto/Haus
        $this->RegisterPropertyBoolean('PVVerteilenAktiv', false); // PV-Leistung anteilig zum Auto leiten
        $this->RegisterPropertyInteger('PVAnteilAuto', 33); // Anteil für das Auto in Prozent
        $this->RegisterPropertyInteger('HausakkuSOCID', 0); // SOC-Variable des Hausakkus
        $this->RegisterPropertyInteger('HausakkuSOCVollSchwelle', 95); // Schwelle ab wann Akku voll gilt

        // Visualisierung & WebFront-Buttons
        $this->RegisterVariableBoolean('ManuellVollladen', '🔌 Manuell: Vollladen aktiv', '', 95);
        $this->EnableAction('ManuellVollladen');

        $this->RegisterVariableBoolean('PV2CarModus', '☀️ PV-Anteil fürs Auto aktiv', '', 96);
        $this->EnableAction('PV2CarModus');

        $this->RegisterVariableBoolean('ZielzeitladungPVonly', '⏱️ Zielzeitladung PV-optimiert', '', 97);
        $this->EnableAction('ZielzeitladungPVonly');

        $this->RegisterVariableString('FahrzeugStatusText', 'Fahrzeug Status', '', 97);
        $this->RegisterVariableString('LademodusStatus', 'Aktueller Lademodus', '', 98);
        $this->RegisterVariableString('WallboxStatusText', 'Wallbox Status', '~HTMLBox', 99);


        $this->RegisterVariableInteger('TargetTime', 'Ziel-Zeit (Uhr)', '~UnixTimestampTime', 60);
        $this->EnableAction('TargetTime');

        // Zykluszeiten & Ladeplanung
        $this->RegisterPropertyInteger('RefreshInterval', 60); // Intervall für die Überschuss-Berechnung (Sekunden)
        $this->RegisterPropertyInteger('TargetChargePreTime', 4); // Stunden vor Zielzeit aktiv laden

        //Für die Berechnung der Ladeverluste
        $this->RegisterAttributeBoolean("ChargingActive", false);
        $this->RegisterAttributeFloat("ChargeSOCStart", 0);
        $this->RegisterAttributeFloat("ChargeEnergyStart", 0);
        $this->RegisterAttributeInteger("ChargeStartTime", 0);

        // Strompreis-Ladung (ab Version 0.9)
        $this->RegisterPropertyInteger("CurrentPriceID", 0);      // Aktueller Preis (ct/kWh, Float)
        $this->RegisterPropertyInteger("ForecastPriceID", 0);     // 24h-Prognose (ct/kWh, String)
        $this->RegisterPropertyFloat("MinPrice", 0.000);       // Mindestpreis (ct/kWh)
        $this->RegisterPropertyFloat("MaxPrice", 30.000);      // Höchstpreis (ct/kWh)

        // Timer für regelmäßige Berechnung
        $this->RegisterTimer('PVUeberschuss_Berechnen', 0, 'IPS_RequestAction(' . $this->InstanceID . ', "UpdateCharging", 0);');
        $this->RegisterTimer('ZyklusLadevorgangCheck', 0, 'IPS_RequestAction(' . $this->InstanceID . ', "ZyklusLadevorgangCheck", 0);');
        
        $this->RegisterPropertyBoolean('ModulAktiv', true);
        $this->RegisterPropertyBoolean('DebugLogging', false);

    }
    
    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->Log('Instanz-Config: ' . json_encode(IPS_GetConfiguration($this->InstanceID)), 'debug');

        $interval = $this->ReadPropertyInteger('RefreshInterval');
        $goeID    = $this->ReadPropertyInteger('GOEChargerID');
        $pvID     = $this->ReadPropertyInteger('PVErzeugungID');

        // Timer nur aktivieren, wenn GO-e und PV-Erzeugung konfiguriert
        if (!$this->ReadPropertyBoolean('ModulAktiv')) {
            // Deaktiviert: Alle Timer aus
            $this->SetTimerInterval('PVUeberschuss_Berechnen', 0);
            $this->SetTimerInterval('ZyklusLadevorgangCheck', 0);
            $this->SetLademodusStatus("⚠️ Modul ist deaktiviert. Keine Aktionen.");
            $this->Log('Modul ist deaktiviert – alle Timer gestoppt.', 'info');
            return;
        }

        // Timer nur aktivieren, wenn GO-e und PV-Erzeugung konfiguriert
        if ($goeID > 0 && $pvID > 0 && $interval > 0) {
            $this->SetTimerInterval('PVUeberschuss_Berechnen', $interval * 1000);
            $this->SetTimerInterval('ZyklusLadevorgangCheck', max($interval, 30) * 1000);
            $this->Log("Timer aktiviert: Intervall PVUeberschuss_Berechnen={$interval}s, ZyklusLadevorgangCheck=" . max($interval, 30) . "s", 'info');
        } else {
            $this->SetTimerInterval('PVUeberschuss_Berechnen', 0);
            $this->SetTimerInterval('ZyklusLadevorgangCheck', 0);
            $this->Log('Timer deaktiviert – GO-e Instanz oder PV-Erzeugung oder Intervall nicht konfiguriert.', 'warn');
        }
    }

    public function RequestAction($ident, $value)
    {
        // NUR Variablen und Modus-Flags setzen! KEINE Statusmeldungen!
        switch ($ident) {
            case 'ManuellVollladen':
                SetValue($this->GetIDForIdent($ident), $value);
                if ($value) {
                    SetValue($this->GetIDForIdent('PV2CarModus'), false);
                    SetValue($this->GetIDForIdent('ZielzeitladungPVonly'), false);
                    SetValue($this->GetIDForIdent('StrompreisModus'), false);
                }
                break;
            case 'PV2CarModus':
                SetValue($this->GetIDForIdent($ident), $value);
                if ($value) {
                    SetValue($this->GetIDForIdent('ManuellVollladen'), false);
                    SetValue($this->GetIDForIdent('ZielzeitladungPVonly'), false);
                    SetValue($this->GetIDForIdent('StrompreisModus'), false);
                }
                break;
            case 'ZielzeitladungPVonly':
                SetValue($this->GetIDForIdent($ident), $value);
                if ($value) {
                    SetValue($this->GetIDForIdent('ManuellVollladen'), false);
                    SetValue($this->GetIDForIdent('PV2CarModus'), false);
                    SetValue($this->GetIDForIdent('StrompreisModus'), false);
                }
                break;
            case 'StrompreisModus':
                SetValue($this->GetIDForIdent($ident), $value);
                if ($value) {
                    SetValue($this->GetIDForIdent('ManuellVollladen'), false);
                    SetValue($this->GetIDForIdent('PV2CarModus'), false);
                    SetValue($this->GetIDForIdent('ZielzeitladungPVonly'), false);
                }
                break;
            case 'TargetTime':
                SetValue($this->GetIDForIdent($ident), $value);
                break;
            default:
                parent::RequestAction($ident, $value);
                break;
        }
        // Hauptlogik am Ende immer aufrufen!
        $this->UpdateCharging();
    }

    public function UpdateCharging()
    {
        $this->WriteAttributeBoolean('RunLogFlag', true); // Start eines neuen Durchlaufs
        $this->Log("Starte Berechnung (UpdateCharging)", 'debug');
    
        $goeID = $this->ReadPropertyInteger('GOEChargerID');
        $status = GOeCharger_GetStatus($goeID); // 1=bereit, 2=lädt, 3=warte, 4=beendet
    
        // Immer: PV-Überschuss (inkl. Batterieabzug) berechnen und anzeigen
        $pvUeberschussStandard = $this->BerechnePVUeberschuss();
        SetValue($this->GetIDForIdent('PV_Ueberschuss'), $pvUeberschussStandard);
        $this->Log("Standard-PV-Überschuss berechnet: {$pvUeberschussStandard} W", 'debug');
    
        // === Fahrzeugstatus-Logik ===
        if ($this->ReadPropertyBoolean('NurMitFahrzeug') && $status == 1) {
            // Wenn kein Fahrzeug verbunden, alle Modi deaktivieren
            foreach (['ManuellVollladen','PV2CarModus','ZielzeitladungPVonly','StrompreisModus'] as $mod) {
                if (GetValue($this->GetIDForIdent($mod))) {
                    SetValue($this->GetIDForIdent($mod), false);
                }
            }
            // Wallbox auf "Bereit" setzen
            if (GOeCharger_getMode($goeID) != 1) {
                GOeCharger_setMode($goeID, 1);
            }
            $this->SetFahrzeugStatus("⚠️ Kein Fahrzeug verbunden – bitte erst Fahrzeug anschließen.");
            SetValue($this->GetIDForIdent('PV_Ueberschuss'), 0.0);
            $this->Log("Kein Fahrzeug verbunden – Abbruch der Berechnung", 'warn');
            $this->UpdateWallboxStatusText();
            return;
        }
        // Status-Logik für weitere Fahrzeugstatus
        if ($this->ReadPropertyBoolean('NurMitFahrzeug')) {
            if ($status == 3) {
                $this->SetFahrzeugStatus("🚗 Fahrzeug angeschlossen, wartet auf Freigabe (z.B. Tür öffnen oder am Fahrzeug 'Laden' aktivieren)");
                $this->Log("Fahrzeug angeschlossen, wartet auf Freigabe", 'debug');
            }
            if ($status == 4) {
                $this->SetFahrzeugStatus("🅿️ Fahrzeug verbunden, Ladung beendet. Moduswechsel möglich.");
                $this->Log("Fahrzeug verbunden, Ladung beendet", 'debug');
            }
        }
    
        // === Ziel-SOC immer berücksichtigen, wenn Option aktiv ===
        if ($this->ReadPropertyBoolean('AlwaysUseTargetSOC')) {
        $socID = $this->ReadPropertyInteger('CarSOCID');
        $soc = (IPS_VariableExists($socID) && $socID > 0) ? GetValue($socID) : $this->ReadPropertyFloat('CarSOCFallback');
        $targetSOCID = $this->ReadPropertyInteger('CarTargetSOCID');
        $targetSOC = (IPS_VariableExists($targetSOCID) && $targetSOCID > 0) ? GetValue($targetSOCID) : $this->ReadPropertyFloat('CarTargetSOCFallback');
        $capacity = $this->ReadPropertyFloat('CarBatteryCapacity'); // z.B. 52.0
    
        $fehlendeProzent = max(0, $targetSOC - $soc);
        $fehlendeKWh = $capacity * $fehlendeProzent / 100.0;
    
        $this->Log("SOC-Prüfung: Ist={$soc}% | Ziel={$targetSOC}% | Fehlend=" . round($fehlendeProzent, 2) . "% | Fehlende kWh=" . round($fehlendeKWh, 2) . " kWh", 'info');
    
        if ($soc >= $targetSOC) {
            $this->SetLadeleistung(0);
            $this->SetLademodusStatus("Ziel-SOC erreicht ({$soc}% ≥ {$targetSOC}%) – keine weitere Ladung.");
            $this->Log("Ziel-SOC erreicht ({$soc}% ≥ {$targetSOC}%) – keine weitere Ladung.", 'info');
            $this->UpdateWallboxStatusText();
            return;
            }
        }
    
        // === Modus-Weiche: NUR eine Logik pro Durchlauf! ===
        // Priorität: Manuell > Zielzeit > PV2Car > Standard
        if (GetValue($this->GetIDForIdent('ManuellVollladen'))) {
            $this->SetLadeleistung($this->GetMaxLadeleistung());
            $this->SetLademodusStatus("Manueller Volllademodus aktiv");
            $this->Log("Modus: Manueller Volllademodus", 'info');
        } elseif (GetValue($this->GetIDForIdent('ZielzeitladungPVonly'))) {
            $this->Log("Modus: Zielzeitladung PV-optimiert", 'info');
            $this->LogikZielzeitladung();
        } elseif (GetValue($this->GetIDForIdent('PV2CarModus'))) {
            $this->Log("Modus: PV2Car aktiv", 'info');
            // --- PV2Car Code, wie gehabt ---
            $pv = 0;
            $pvID = $this->ReadPropertyInteger('PVErzeugungID');
            if ($pvID > 0 && @IPS_VariableExists($pvID)) {
                $pv = GetValue($pvID);
                if ($this->ReadPropertyString('PVErzeugungEinheit') == 'kW') {
                    $pv *= 1000;
                }
            }
            $haus = $this->GetNormWert('HausverbrauchID', 'HausverbrauchEinheit', 'InvertHausverbrauch', "Hausverbrauch");
            $pvUeberschussDirekt = max(0, $pv - $haus);
    
            // Hausakku SoC prüfen ...
            $hausakkuSocID = $this->ReadPropertyInteger('HausakkuSOCID');
            $hausakkuSocVoll = $this->ReadPropertyInteger('HausakkuSOCVollSchwelle');
            $hausakkuSoc = 0;
            if ($hausakkuSocID > 0 && @IPS_VariableExists($hausakkuSocID)) {
                $hausakkuSoc = GetValue($hausakkuSocID);
            }
            $anteil = $this->ReadPropertyInteger('PVAnteilAuto');
            $autoProzent = $anteil;
            $restProzent = 100 - $anteil;
            if ($hausakkuSoc >= $hausakkuSocVoll) {
                $autoProzent = 100;
                $restProzent = 0;
            }
            $ladeWatt = min(max(round($pvUeberschussDirekt * ($autoProzent / 100.0)), 0), $this->GetMaxLadeleistung());
            $info = "PV2Car: {$autoProzent}% vom Überschuss ({$ladeWatt} W)";
            if ($autoProzent == 100) {
                $info .= " (Hausakku voll, 100 % ins Auto)";
            } else {
                $info .= " ({$restProzent}% zur Batterie)";
            }
            $this->SetLadeleistung($ladeWatt);
            $this->SetLademodusStatus($info);
            $this->Log("PV2Car: Anteil Auto: {$autoProzent}% | Ladeleistung: {$ladeWatt} W | Rest zur Batterie: {$restProzent}%", 'debug');
        } else {
            // === Standard: Nur PV-Überschuss/Hysterese ===
            $this->Log("Modus: PV-Überschuss (Standard)", 'info');
            $this->LogikPVPureMitHysterese();
        }
    
        // Optional: WallboxStatusText für WebFront aktualisieren (nur einmal pro Zyklus)
        $this->UpdateWallboxStatusText();
    }

    // --- Hilfsfunktion: PV-Überschuss berechnen ---
    // Modus kann 'standard' (bisher wie gehabt) oder 'pv2car' (neuer PV2Car-Modus) sein
    private function BerechnePVUeberschuss(string $modus = 'standard'): float
    {
        $goeID  = $this->ReadPropertyInteger("GOEChargerID");
    
        // Werte auslesen, immer auf Watt normiert
        $pv    = 0;
        $pvID  = $this->ReadPropertyInteger('PVErzeugungID');
        if ($pvID > 0 && @IPS_VariableExists($pvID)) {
            $pv = GetValue($pvID);
            if ($this->ReadPropertyString('PVErzeugungEinheit') == 'kW') {
                $pv *= 1000;
            }
        }
        
        $haus  = $this->GetNormWert('HausverbrauchID', 'HausverbrauchEinheit', 'InvertHausverbrauch', "Hausverbrauch");
        $batt  = $this->GetNormWert('BatterieladungID', 'BatterieladungEinheit', 'InvertBatterieladung', "Batterieladung");
        $netz  = $this->GetNormWert('NetzeinspeisungID', 'NetzeinspeisungEinheit', 'InvertNetzeinspeisung', "Netzeinspeisung");
    
        // Ladeleistung (optional für Debugging)
        $ladeleistung = ($goeID > 0) ? GOeCharger_GetPowerToCar($goeID) : 0;
    
        // --- Unterscheidung nach Modus ---
        if ($modus == 'pv2car') {
            // Anteil direkt ans Auto (Rest für Batterie)
            $ueberschuss = $pv - $haus;
            $logModus = "PV2Car (Auto bekommt Anteil vom Überschuss, Rest Batterie)";
        } else {
            // Standard: Batterie bekommt Vorrang
            $ueberschuss = $pv - $haus - max(0, $batt);
            $logModus = "Standard (Batterie hat Vorrang)";
        }
    
        // Dynamischer Puffer
        $puffer = 1.0;
        if ($this->ReadPropertyBoolean('DynamischerPufferAktiv')) {
            if ($ueberschuss < 2000)      $puffer = 0.80;
            elseif ($ueberschuss < 4000)  $puffer = 0.85;
            elseif ($ueberschuss < 6000)  $puffer = 0.90;
            else                          $puffer = 0.93;
            $alterUeberschuss = $ueberschuss;
            $ueberschuss *= $puffer;
                    }
        
        // Auf Ganzzahl runden und negatives abfangen
        $ueberschuss = max(0, round($ueberschuss));

        // --- Zentrales Logging ---
        $this->Log(
            "[{$logModus}] PV: {$pv} W | Haus: {$haus} W | Batterie: {$batt} W | Netz: {$netz} W | Ladeleistung: {$ladeleistung} W | → Überschuss: {$ueberschuss} W",
            'info'
        );

        // In Variable schreiben (nur im Standardmodus als Visualisierung)
        if ($modus == 'standard') {
            SetValue($this->GetIDForIdent('PV_Ueberschuss'), $ueberschuss);
        }

        return $ueberschuss;
    }


    // --- Hysterese-Logik für Standardmodus ---
    private function LogikPVPureMitHysterese()
    {
        $minStart = $this->ReadPropertyInteger('MinLadeWatt');
        $minStop  = $this->ReadPropertyInteger('MinStopWatt');
        $ueberschuss = $this->BerechnePVUeberschuss('standard');
        $goeID = $this->ReadPropertyInteger('GOEChargerID');
        $ladeModusID = @IPS_GetObjectIDByIdent('accessStateV2', $goeID);
        $ladeModus = ($ladeModusID !== false && @IPS_VariableExists($ladeModusID)) ? GetValueInteger($ladeModusID) : 0;
    
        $this->Log(
            "Hysterese: Modus={$ladeModus}, Überschuss={$ueberschuss} W, MinStart={$minStart} W, MinStop={$minStop} W",
            'info'
        );
    
        if ($ladeModus == 2) { // Lädt bereits
            // === Stop-Hysterese ===
            if ($ueberschuss <= $minStop) {
                $counter = $this->ReadAttributeInteger('StopHystereseCounter') + 1;
                $this->WriteAttributeInteger('StopHystereseCounter', $counter);
                $this->Log("🛑 Stop-Hysterese: {$counter}/" . ($this->ReadPropertyInteger('StopHysterese')+1), 'debug');
                if ($counter > $this->ReadPropertyInteger('StopHysterese')) {
                    $this->SetLadeleistung(0);
                    // **Explizit Wallbox auf Modus "Bereit" stellen!**
                    if (@IPS_InstanceExists($goeID)) {
                        GOeCharger_setMode($goeID, 1); // 1 = Bereit
                        $this->Log("🔌 Wallbox-Modus auf 'Bereit' gestellt (1)", 'info');
                    }
                    $msg = "PV-Überschuss unter Stop-Schwelle ({$ueberschuss} W ≤ {$minStop} W) – Wallbox gestoppt";
                    $this->Log($msg, 'info');
                    $this->SetLademodusStatus($msg);
                    $this->WriteAttributeInteger('StopHystereseCounter', 0);
                }
            } else {
                $this->WriteAttributeInteger('StopHystereseCounter', 0);
                $this->SetLadeleistung($ueberschuss);
                // Hier Modus auf 2 (Laden) nur wenn wirklich > 0!
                if ($ueberschuss > 0) {
                    if (@IPS_InstanceExists($goeID)) {
                        GOeCharger_setMode($goeID, 2); // 2 = Laden erzwingen
                        $this->Log("⚡ Wallbox-Modus auf 'Laden' gestellt (2)", 'info');
                    }
                }
                $msg = "PV-Überschuss: Bleibt an ({$ueberschuss} W)";
                $this->Log($msg, 'info');
                $this->SetLademodusStatus($msg);
            }
        } else { // Lädt NICHT
            // === Start-Hysterese ===
            if ($ueberschuss >= $minStart) {
                $counter = $this->ReadAttributeInteger('StartHystereseCounter') + 1;
                $this->WriteAttributeInteger('StartHystereseCounter', $counter);
                $this->Log("🟢 Start-Hysterese: {$counter}/" . ($this->ReadPropertyInteger('StartHysterese')+1), 'debug');
                if ($counter > $this->ReadPropertyInteger('StartHysterese')) {
                    $this->SetLadeleistung($ueberschuss);
                    // Hier Modus auf 2 (Laden) nur wenn wirklich > 0!
                    if ($ueberschuss > 0) {
                        if (@IPS_InstanceExists($goeID)) {
                            GOeCharger_setMode($goeID, 2); // 2 = Laden erzwingen
                            $this->Log("⚡ Wallbox-Modus auf 'Laden' gestellt (2)", 'info');
                        }
                    }
                    $msg = "PV-Überschuss über Start-Schwelle ({$ueberschuss} W ≥ {$minStart} W) – Wallbox startet";
                    $this->Log($msg, 'info');
                    $this->SetLademodusStatus($msg);
                    $this->WriteAttributeInteger('StartHystereseCounter', 0);
                }
            } else {
                $this->WriteAttributeInteger('StartHystereseCounter', 0);
                $this->SetLadeleistung(0);
                // **Immer Modus auf "Bereit" stellen, solange kein Überschuss!**
                if (@IPS_InstanceExists($goeID)) {
                    GOeCharger_setMode($goeID, 1); // 1 = Bereit
                    $this->Log("🔌 Wallbox-Modus auf 'Bereit' gestellt (1)", 'info');
                }
                $msg = "PV-Überschuss zu niedrig ({$ueberschuss} W) – bleibt aus";
                $this->Log($msg, 'info');
                $this->SetLademodusStatus($msg);
            }
        }
    }

    // --- Zielzeitladung-Logik: ---
    private function LogikZielzeitladung()
    {
        // Zielzeit holen & ggf. auf nächsten Tag anpassen
        $targetTimeVarID = $this->GetIDForIdent('TargetTime');
        $targetTime = GetValue($targetTimeVarID);
        $now = time();
        if ($targetTime < $now) $targetTime += 86400;
    
        // SOC & Ziel-SOC holen
        $socID = $this->ReadPropertyInteger('CarSOCID');
        $soc = (IPS_VariableExists($socID) && $socID > 0) ? GetValue($socID) : $this->ReadPropertyFloat('CarSOCFallback');
        $targetSOCID = $this->ReadPropertyInteger('CarTargetSOCID');
        $targetSOC = (IPS_VariableExists($targetSOCID) && $targetSOCID > 0) ? GetValue($targetSOCID) : $this->ReadPropertyFloat('CarTargetSOCFallback');
        $capacity = $this->ReadPropertyFloat('CarBatteryCapacity'); // z.B. 52.0 kWh
    
        // Restenergie und Zeit
        $fehlendeProzent = max(0, $targetSOC - $soc);
        $fehlendeKWh = $capacity * $fehlendeProzent / 100.0;
    
        // Ziel erreicht?
        if ($fehlendeProzent <= 0) {
            $this->SetLadeleistung(0);
            $msg = "Zielzeitladung: Ziel-SOC erreicht – keine Ladung mehr erforderlich";
            $this->Log($msg, 'info');
            $this->SetLademodusStatus($msg);
            return;
        }
    
        // ==== Forecast auslesen (falls vorhanden) ====
        $forecastVarID = $this->ReadPropertyInteger("ForecastPriceID");
        $forecast = [];
        if ($forecastVarID > 0 && @IPS_VariableExists($forecastVarID)) {
            $forecastString = GetValue($forecastVarID);
            $forecast = json_decode($forecastString, true); // Wenn es JSON ist!
            if (!is_array($forecast)) {
                $forecast = array_map('floatval', explode(';', $forecastString));
            }
        }
    
        $maxWatt = $this->GetMaxLadeleistung();
        $ladezeitStd = $fehlendeKWh / ($maxWatt / 1000.0); // kWh / (kW) = h
    
        if (!is_array($forecast) || count($forecast) < 1) {
            $this->Log("Forecast: Keine gültigen Prognosedaten gefunden – Standard-Zielzeit-Logik wird verwendet.", 'warn');
        }
    
        if (is_array($forecast) && count($forecast) >= 1) {
            $nowHour = intval(date('G', $now));
            $stundenslots = [];
            for ($i = 0; $i < count($forecast); $i++) {
                $slotTime = $now + $i * 3600;
                if ($slotTime > $targetTime) continue;
                $stundenslots[] = [
                    "index" => $i,
                    "price" => floatval($forecast[$i]),
                    "time" => $slotTime,
                ];
            }
            // Günstigste n-Stunden-Fenster finden
            usort($stundenslots, function($a, $b) { return $a["price"] <=> $b["price"]; });
    
            $ladeStunden = ceil($ladezeitStd);
            $ladezeiten = array_slice($stundenslots, 0, $ladeStunden);
    
            // Logging Ladefenster (debug)
            $ladeFensterTxt = implode(", ", array_map(function($slot) {
                return date('H', $slot["time"]) . "h: " . round($slot["price"], 2) . "ct";
            }, $ladezeiten));
            $this->Log("Forecast: Ladefenster gewählt: {$ladeFensterTxt}", 'debug');
    
            $aktuelleStunde = intval(date('G', $now));
            $ladeJetzt = false;
            $aktuellerSlotPrice = null;
            foreach ($ladezeiten as $slot) {
                if (intval(date('G', $slot["time"])) == $aktuelleStunde) {
                    $ladeJetzt = true;
                    $aktuellerSlotPrice = $slot["price"];
                    break;
                }
            }
    
            if ($ladeJetzt) {
                $this->SetLadeleistung($maxWatt);
                $msg = "Forecast: Lade in günstigster Stunde (" . round($aktuellerSlotPrice, 2) . " ct/kWh), Rest: " . round($fehlendeKWh, 2) . " kWh";
                $this->Log($msg, 'info');
                $this->SetLademodusStatus($msg);
            } else {
                // Nicht laden, außer PV-Überschuss ist vorhanden!
                $pvUeberschuss = $this->BerechnePVUeberschuss();
                if ($pvUeberschuss > 0) {
                    $msg = "Forecast: Lade nur mit PV-Überschuss, Rest: " . round($fehlendeKWh, 2) . " kWh";
                    $this->SetLadeleistung($pvUeberschuss);
                    $this->Log($msg, 'info');
                    $this->SetLademodusStatus($msg);
                } else {
                    $msg = "Forecast: Warte auf günstigen Tarif oder PV, Rest: " . round($fehlendeKWh, 2) . " kWh";
                    $this->SetLadeleistung(0);
                    $this->Log($msg, 'info');
                    $this->SetLademodusStatus($msg);
                }
            }
            return;
        }
    
        // Ladeleistung bestimmen (PV-only bis x Stunden vor Zielzeit, dann volle Leistung)
        $maxWatt = $this->GetMaxLadeleistung();
        $minWatt = $this->ReadPropertyInteger('MinLadeWatt');
        $pvUeberschuss = $this->BerechnePVUeberschuss();
        $ladewatt = max($pvUeberschuss, $minWatt);
    
        // Reststunden berechnen
        $ladeleistung_kW = $ladewatt / 1000.0;
        $restStunden = ($ladeleistung_kW > 0) ? round($fehlendeKWh / $ladeleistung_kW, 2) : 99;
    
        // Umschaltzeit berechnen
        $stundenVorher = $this->ReadPropertyInteger('TargetChargePreTime');
        $forceTime = $targetTime - ($stundenVorher * 3600);
    
        if ($now >= $forceTime) {
            $msg = "Zielzeitladung: Maximale Leistung (Netzbezug möglich, {$fehlendeKWh} kWh fehlen)";
            $this->SetLadeleistung($maxWatt);
            $this->Log("Zielzeitladung: Netzbezug erlaubt, maximale Leistung {$maxWatt} W – {$fehlendeKWh} kWh fehlen", 'info');
            $this->SetLademodusStatus($msg);
        } else {
            $bisWann = date('H:i', $forceTime);
            $msg = "Zielzeitladung: Nur PV-Überschuss bis $bisWann Uhr – {$fehlendeKWh} kWh fehlen ({$restStunden} h nötig)";
            $this->SetLadeleistung($pvUeberschuss);
            $this->Log("Zielzeitladung: Nur PV-Überschuss – noch {$fehlendeKWh} kWh, Restzeit ca. {$restStunden} h, Umschaltung um $bisWann Uhr", 'info');
            $this->SetLademodusStatus($msg);
        }
    }
    
    private function GetMaxLadeleistung(): int
    {
        $phasen = $this->ReadPropertyInteger('Phasen');
        $maxAmp = $this->ReadPropertyInteger('MaxAmpere');
        return $phasen * 230 * $maxAmp;
    }
    
    private function SetLadeleistung(int $watt)
    {
        $typ = 'go-e';
    
        switch ($typ) {
            case 'go-e':
                $goeID = $this->ReadPropertyInteger('GOEChargerID');
                if (!@IPS_InstanceExists($goeID)) {
                    $this->Log("⚠️ go-e Charger Instanz nicht gefunden (ID: $goeID)", 'warn');
                    return;
                }
    
                // Counter nur bei > 0 W prüfen, sonst zurücksetzen
                if ($watt > 0) {
                    // Phasenumschaltung prüfen
                    $phaseVarID = @IPS_GetObjectIDByIdent('SinglePhaseCharging', $goeID);
                    $aktuell1phasig = false;
                    if ($phaseVarID !== false && @IPS_VariableExists($phaseVarID)) {
                        $aktuell1phasig = GetValueBoolean($phaseVarID);
                    }
    
                    // Hysterese für Umschaltung 1-phasig
                    if ($watt < $this->ReadPropertyInteger('Phasen1Schwelle') && !$aktuell1phasig) {
                        $alterCounter = $this->ReadAttributeInteger('Phasen1Counter');
                        $counter = $alterCounter + 1;
                        $this->WriteAttributeInteger('Phasen1Counter', $counter);
                        $this->WriteAttributeInteger('Phasen3Counter', 0);
                        // **Nur loggen, wenn sich der Counter erhöht**
                        if ($counter !== $alterCounter) {
                            $this->Log("⏬ Zähler 1-phasig: {$counter} / {$this->ReadPropertyInteger('Phasen1Limit')}", 'info');
                        }
                        if ($counter >= $this->ReadPropertyInteger('Phasen1Limit')) {
                            if (!$aktuell1phasig) {
                                GOeCharger_SetSinglePhaseCharging($goeID, true);
                                $this->Log("🔁 Umschaltung auf 1-phasig ausgelöst", 'info');
                            }
                            $this->WriteAttributeInteger('Phasen1Counter', 0);
                        }
                    }
                    // Hysterese für Umschaltung 3-phasig
                    elseif ($watt > $this->ReadPropertyInteger('Phasen3Schwelle') && $aktuell1phasig) {
                        $alterCounter = $this->ReadAttributeInteger('Phasen3Counter');
                        $counter = $alterCounter + 1;
                        $this->WriteAttributeInteger('Phasen3Counter', $counter);
                        $this->WriteAttributeInteger('Phasen1Counter', 0);
                        // **Nur loggen, wenn sich der Counter erhöht**
                        if ($counter !== $alterCounter) {
                            $this->Log("⏫ Zähler 3-phasig: {$counter} / {$this->ReadPropertyInteger('Phasen3Limit')}", 'info');
                        }
                        if ($counter >= $this->ReadPropertyInteger('Phasen3Limit')) {
                            if ($aktuell1phasig) {
                                GOeCharger_SetSinglePhaseCharging($goeID, false);
                                $this->Log("🔁 Umschaltung auf 3-phasig ausgelöst", 'info');
                            }
                            $this->WriteAttributeInteger('Phasen3Counter', 0);
                        }
                    }
                    // Keine Umschaltbedingung – Zähler zurücksetzen
                    else {
                        $this->WriteAttributeInteger('Phasen1Counter', 0);
                        $this->WriteAttributeInteger('Phasen3Counter', 0);
                    }
                } else {
                    // Zähler zurücksetzen, wenn Leistung 0
                    $this->WriteAttributeInteger('Phasen1Counter', 0);
                    $this->WriteAttributeInteger('Phasen3Counter', 0);
                }
    
                // Modus & Ladeleistung nur setzen, wenn nötig
                $modusID = @IPS_GetObjectIDByIdent('accessStateV2', $goeID);
                $wattID  = @IPS_GetObjectIDByIdent('Watt', $goeID);
    
                $aktuellerModus = -1;
                if ($modusID !== false && @IPS_VariableExists($modusID)) {
                    $aktuellerModus = GetValueInteger($modusID);
                }
    
                $aktuelleLeistung = -1;
                if ($wattID !== false && @IPS_VariableExists($wattID)) {
                    $aktuelleLeistung = GetValueFloat($wattID);
                }
    
                // === Ladeleistung nur setzen, wenn Änderung > 50 W ===
                if ($aktuelleLeistung < 0 || abs($aktuelleLeistung - $watt) > 50) {
                    GOeCharger_SetCurrentChargingWatt($goeID, $watt);
                    $this->Log("✅ Ladeleistung gesetzt: {$watt} W", 'info');
    
                    // Nach Setzen der Leistung Modus sicherheitshalber aktivieren:
                    if ($watt > 0 && $aktuellerModus != 2) {
                        GOeCharger_setMode($goeID, 2); // 2 = Laden erzwingen
                        $this->Log("⚡ Modus auf 'Laden' gestellt (2)", 'info');
                    }
                    if ($watt == 0 && $aktuellerModus != 1) {
                        GOeCharger_setMode($goeID, 1); // 1 = Bereit
                        $this->Log("🔌 Modus auf 'Bereit' gestellt (1)", 'info');
                    }
                } else {
                    $this->Log("🟡 Ladeleistung unverändert – keine Änderung notwendig", 'debug');
                }
                // Prüfe: Leistung > 0, Modus ist "bereit" (1), Fahrzeug verbunden (Status 3 oder 4)
                $status = GOeCharger_GetStatus($goeID); // 1=bereit, 2=lädt, 3=warte, 4=beendet
                if ($watt > 0 && $aktuellerModus == 1 && in_array($status, [3, 4])) {
                    $msg = "⚠️ Ladeleistung gesetzt, aber die Ladung startet nicht automatisch.<br>
                            Bitte Fahrzeug einmal ab- und wieder anstecken, um die Ladung zu aktivieren!";
                    $this->SetLademodusStatus($msg);
                    $this->Log($msg, 'warn');
                }
                break;
            default:
                $this->Log("❌ Unbekannter Wallbox-Typ '$typ' – keine Steuerung durchgeführt.", 'error');
                break;
        }
    }

    private function SetLademodusStatus(string $text)
    {
        $varID = $this->GetIDForIdent('LademodusStatus');
        if ($varID !== false && @IPS_VariableExists($varID)) {
            if (GetValue($varID) !== $text) {
                SetValue($varID, $text);
            }
        }
    }

    private function SetFahrzeugStatus(string $text)
    {
        $varID = $this->GetIDForIdent('FahrzeugStatusText');
        if ($varID !== false && @IPS_VariableExists($varID)) {
            if (GetValue($varID) !== $text) {
                SetValue($varID, $text);
            }
        }
    }
    
    // --- Ladeverluste automatisch berechnen, wenn alle Werte vorhanden ---
    private function BerechneLadeverluste($socStart, $socEnde, $batteryCapacity, $wbEnergy)
    {
        $errors = [];
        if ($batteryCapacity <= 0) $errors[] = "Batteriekapazität";
        if ($socStart < 0 || $socEnde < 0) $errors[] = "SOC-Start/Ende";
        if ($wbEnergy <= 0) $errors[] = "Wallbox-Energie";
    
        if (count($errors) > 0) {
            $msg = "⚠️ Ladeverluste nicht berechnet: Fehlende/falsche Werte: " . implode(", ", $errors);
            $this->Log($msg, 'warn');
            $this->SetLadeverlustInfo($msg);
            return;
        }
    
        $gespeichert = (($socEnde - $socStart) / 100) * $batteryCapacity;
        $verlustAbsolut = $wbEnergy - $gespeichert;
        $verlustProzent = $wbEnergy > 0 ? ($verlustAbsolut / $wbEnergy) * 100 : 0;
    
        // Profile prüfen/erstellen und Variablen registrieren
        $profil_kwh = "~Electricity";
        if (!IPS_VariableProfileExists($profil_kwh)) {
            IPS_CreateVariableProfile($profil_kwh, 2);
            IPS_SetVariableProfileDigits($profil_kwh, 2);
            IPS_SetVariableProfileText($profil_kwh, "", " kWh");
        }
        $profil_percent = "~Intensity.100";
        if (!IPS_VariableProfileExists($profil_percent)) {
            IPS_CreateVariableProfile($profil_percent, 2);
            IPS_SetVariableProfileDigits($profil_percent, 1);
            IPS_SetVariableProfileText($profil_percent, "", " %");
            IPS_SetVariableProfileValues($profil_percent, 0, 100, 1);
        }
        $this->RegisterVariableFloat('Ladeverlust_Absolut', 'Ladeverlust absolut (kWh)', $profil_kwh, 100);
        $this->RegisterVariableFloat('Ladeverlust_Prozent', 'Ladeverlust (%)', $profil_percent, 110);
    
        // Logging aktivieren (einmalig)
        $archiveID = @IPS_GetInstanceIDByName('Archiv', 0);
        if ($archiveID === false) $archiveID = 1;
        @AC_SetLoggingStatus($archiveID, $this->GetIDForIdent('Ladeverlust_Absolut'), true);
        @AC_SetLoggingStatus($archiveID, $this->GetIDForIdent('Ladeverlust_Prozent'), true);
    
        SetValue($this->GetIDForIdent('Ladeverlust_Absolut'), round($verlustAbsolut, 2));
        SetValue($this->GetIDForIdent('Ladeverlust_Prozent'), round($verlustProzent, 1));
    
        $msg = "Ladeverluste berechnet: absolut=" . round($verlustAbsolut, 2) . " kWh, prozentual=" . round($verlustProzent, 1) . " %";
        $this->Log($msg, 'info');
        $this->SetLadeverlustInfo($msg);
    }

    private function SetLadeverlustInfo($msg)
    {
        $this->RegisterVariableString('Ladeverlust_Info', 'Ladeverlust Status', '', 120);
        SetValue($this->GetIDForIdent('Ladeverlust_Info'), $msg);
    }
    
    // Ladevorgang-Start
    private function LadevorgangStart($aktuellerSOC, $aktuellerWBZähler)
    {
        $this->WriteAttributeBoolean("ChargingActive", true);
        $this->WriteAttributeFloat("ChargeSOCStart", $aktuellerSOC);
        $this->WriteAttributeFloat("ChargeEnergyStart", $aktuellerWBZähler);
        $this->WriteAttributeInteger("ChargeStartTime", time());
    }
    
    // Ladevorgang-Ende
    private function LadevorgangEnde($aktuellerSOC, $aktuellerWBZähler, $batteryCapacity)
    {
        $socStart = $this->ReadAttributeFloat("ChargeSOCStart");
        $socEnde  = $aktuellerSOC;
        $energyStart = $this->ReadAttributeFloat("ChargeEnergyStart");
        $energyEnd   = $aktuellerWBZähler;
        $wbEnergy = $energyEnd - $energyStart;
    
        $this->Log("LadevorgangEnde: SOC von $socStart auf $socEnde, Energie von $energyStart auf $energyEnd, WB-Energie $wbEnergy kWh", 'info');
        $this->BerechneLadeverluste($socStart, $socEnde, $batteryCapacity, $wbEnergy);
    
        // Reset Status
        $this->WriteAttributeBoolean("ChargingActive", false);
    }

    public function ZyklusLadevorgangCheck()
    {
        $goeID = $this->ReadPropertyInteger("GOEChargerID");
        $carSOCID = $this->ReadPropertyInteger("CarSOCID");
        $batteryCapacity = $this->ReadPropertyFloat("CarBatteryCapacity");
    
        // Robustheit: Fehlende Variablen abfangen!
        if ($goeID == 0 || $carSOCID == 0 || !@IPS_VariableExists($carSOCID)) {
            $this->Log("Ladeverluste nicht berechnet, da GO-e oder Fahrzeug-SOC-Variable fehlt!", 'warn');
            $this->SetLadeverlustInfo("⚠️ Ladeverluste nicht berechnet, da GO-e oder Fahrzeug-SOC-Variable fehlt!");
            return;
        }
    
        $status = GOeCharger_GetStatus($goeID); // 2/4=verbunden, 1/0=getrennt
        $aktuellerSOC = GetValue($carSOCID);
        $aktuellerWBZähler = GOeCharger_GetEnergyTotal($goeID); // in kWh
    
        if (in_array($status, [2, 4])) {
            if (!$this->ReadAttributeBoolean("ChargingActive")) {
                // Ladefenster startet
                $this->Log("Ladevorgang gestartet: SOC={$aktuellerSOC}, WB-Zähler={$aktuellerWBZähler} kWh", 'info');
                $this->LadevorgangStart($aktuellerSOC, $aktuellerWBZähler);
            }
        } else {
            if ($this->ReadAttributeBoolean("ChargingActive")) {
                // Ladefenster endet
                $this->Log("Ladevorgang beendet: SOC={$aktuellerSOC}, WB-Zähler={$aktuellerWBZähler} kWh", 'info');
                $this->LadevorgangEnde($aktuellerSOC, $aktuellerWBZähler, $batteryCapacity);
            }
        }
    }

    private function GetNormWert(string $idProp, string $einheitProp, string $invertProp, string $name = ""): float
    {
        $wert = 0;
        $vid = $this->ReadPropertyInteger($idProp);
        if ($vid > 0 && @IPS_VariableExists($vid)) {
            $wert = GetValue($vid);
            if ($this->ReadPropertyBoolean($invertProp)) {
                $wert *= -1;
            }
            if ($this->ReadPropertyString($einheitProp) == "kW") {
                $wert *= 1000;
            }
        } else {
            if ($name != "") {
                $this->Log("Hinweis: Keine $name-Variable gewählt, Wert wird als 0 angesetzt.", 'debug');
            }
        }
        return $wert;
    }

    private function UpdateWallboxStatusText()
    {
        $goeID = $this->ReadPropertyInteger('GOEChargerID');
        if ($goeID == 0) {
            $text = '<span style="color:gray;">Keine GO-e Instanz gewählt</span>';
        } else {
            $status = GOeCharger_GetStatus($goeID);
            switch ($status) {
                case 1:
                    $text = '<span style="color: gray;">Ladestation bereit, kein Fahrzeug</span>';
                    break;
                case 2:
                    $text = '<span style="color: green; font-weight:bold;">Fahrzeug lädt</span>';
                    break;
                case 3:
                    $text = '<span style="color: orange;">Fahrzeug angeschlossen, wartet auf Ladefreigabe</span>';
                    break;
                case 4:
                    $text = '<span style="color: blue;">Ladung beendet, Fahrzeug verbunden</span>';
                    break;
                default:
                    $text = '<span style="color: red;">Unbekannter Status</span>';
                    $this->Log('warn', "Unbekannter Status vom GO-e Charger: $status");
            }
        }
        $varID = $this->GetIDForIdent('WallboxStatusText');
        if ($varID !== false && @IPS_VariableExists($varID)) {
            if (GetValue($varID) !== $text) {
                SetValue($varID, $text);
            }
        }
    }

    private function Log(string $message, string $level)

    {
        // Unterstützte Level: debug, info, warn, warning, error
        $prefix = "PVWM";
        $normalized = strtolower(trim($level));

        // Unerwünschte/zu kurze Nachrichten unterdrücken
        if (in_array(strtolower(trim($message)), ['warn', 'debug', 'info', ''])) {
            return;
        }

        switch ($normalized) {
            case 'debug':
                if ($this->ReadPropertyBoolean('DebugLogging')) {
                    IPS_LogMessage("{$prefix} [DEBUG]", $message);
                    $this->SendDebug("DEBUG", $message, 0);
                }
                break;
            case 'warn':
            case 'warning':
                IPS_LogMessage("{$prefix} [WARN]", $message);
                break;
            case 'error':
                IPS_LogMessage("{$prefix} [ERROR]", $message);
                break;
            case 'info':
            case '':
            case null:
                IPS_LogMessage("{$prefix}", $message);
                break;
            default:
                IPS_LogMessage("{$prefix}", $message);
                break;
        }
    }
}
