; Instalador del agente SII de Icontador para Windows (Inno Setup 6).
; Lo compila construir-instalador.ps1 a partir de una carpeta "staging" que ya trae Node portable,
; el agente, assets\rcv-import.js y el Chromium de Playwright.
; No pide ni guarda claves tributarias. Pide un código de emparejamiento de un solo uso: el agente lo
; canjea por su token propio al primer arranque y lo borra de .env.

#define AppName "Icontador Agente SII"
#define AppVersion "1.0.0"
#ifndef SourceDir
  #define SourceDir "..\build\staging"
#endif

[Setup]
AppId={{6E1C2B7A-3F4D-4B8E-9A21-5D0C7F3E8B19}
AppName={#AppName}
AppVersion={#AppVersion}
AppPublisher=Icontador
DefaultDirName={localappdata}\IcontadorSII
DisableDirPage=yes
DisableProgramGroupPage=yes
PrivilegesRequired=lowest
OutputDir=..\build\salida
OutputBaseFilename=IcontadorSII-Agente-{#AppVersion}
Compression=lzma2
SolidCompression=yes
WizardStyle=modern
UninstallDisplayName={#AppName}

[Languages]
Name: "spanish"; MessagesFile: "compiler:Languages\Spanish.isl"

[Files]
Source: "{#SourceDir}\*"; DestDir: "{app}"; Flags: recursesubdirs createallsubdirs ignoreversion

[Run]
Filename: "{sys}\wscript.exe"; Parameters: """{app}\lanzador.vbs"""; Flags: postinstall nowait; Description: "Iniciar el agente en una terminal"

[Code]
var
  PaginaConexion: TInputQueryWizardPage;

procedure InitializeWizard;
begin
  PaginaConexion := CreateInputQueryPage(wpSelectDir,
    'Conexión con Cifrax',
    'Indica la dirección de Cifrax y el código de emparejamiento.',
    'Genera el código en Cifrax, en RCV, con Vincular este PC. La clave tributaria no se pide aquí.');
  PaginaConexion.Add('Dirección de Cifrax (por ejemplo https://qlc.cl):', False);
  PaginaConexion.Add('Código de emparejamiento (vence en 10 minutos):', False);
  PaginaConexion.Values[0] := 'https://qlc.cl';
end;

function NextButtonClick(CurPageID: Integer): Boolean;
var
  url: String;
begin
  Result := True;
  if CurPageID = PaginaConexion.ID then
  begin
    url := LowerCase(Trim(PaginaConexion.Values[0]));
    if (Pos('https://', url) <> 1) or (Length(Trim(PaginaConexion.Values[1])) < 8) then
    begin
      MsgBox('Revisa la dirección de Cifrax (debe empezar con https://) y el código de emparejamiento.', mbError, MB_OK);
      Result := False;
    end;
  end;
end;

procedure CurStepChanged(CurStep: TSetupStep);
var
  url, contenido: String;
begin
  if CurStep = ssPostInstall then
  begin
    url := Trim(PaginaConexion.Values[0]);
    while (Length(url) > 0) and (url[Length(url)] = '/') do
      Delete(url, Length(url), 1);

    contenido := 'SII_URL_SERVIDOR=' + url + #13#10 +
                 'SII_CODIGO_EMPAREJAMIENTO=' + Trim(PaginaConexion.Values[1]) + #13#10 +
                 'SII_HEADLESS=true' + #13#10;
    SaveStringToFile(ExpandConstant('{app}\sii-agent\.env'), contenido, False);

  end;
end;
