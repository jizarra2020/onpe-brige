import fs from 'fs';
import path from 'path';
import { execSync } from 'child_process';

const filesToInclude = [
    'routes/web.php',
    'routes/console.php',
    'bootstrap/app.php',
    'bootstrap/providers.php',
    'config/services.php',
    'app/Http/Controllers/OnpeConsultaController.php',
    'app/Services/Onpe/Contracts/OnpeProviderInterface.php',
    'app/Services/Onpe/DTO/OnpeConsultaDTO.php',
    'app/Services/Onpe/Providers/CustomApiOnpeProvider.php',
    'app/Services/Onpe/Providers/MockOnpeProvider.php',
    'app/Services/Onpe/Providers/NativeHttpOnpeProvider.php',
    'app/Services/Onpe/Providers/OnpeDirectBridgeProvider.php',
    'app/Services/Onpe/Scripts/onpe_live_bridge.js',
    'app/Services/Onpe/Scripts/onpe_waf_generator.js',
    'app/Services/Onpe/Support/WafTokenManager.php',
    'app/Services/Onpe/OnpeConsultaService.php',
    'app/Support/OnpeResponseMapper.php'
];

const tempDir = path.join(process.cwd(), 'temp_cpanel_deploy');
if (fs.existsSync(tempDir)) {
    fs.rmSync(tempDir, { recursive: true, force: true });
}
fs.mkdirSync(tempDir, { recursive: true });

for (const rel of filesToInclude) {
    const src = path.join(process.cwd(), rel);
    const dest = path.join(tempDir, rel);
    fs.mkdirSync(path.dirname(dest), { recursive: true });
    fs.copyFileSync(src, dest);
}

const zipOut = path.join(process.cwd(), 'actualizacion_cpanel_onpe.zip');
if (fs.existsSync(zipOut)) {
    fs.unlinkSync(zipOut);
}

// Compress using powershell from inside tempDir
execSync(`powershell -Command "Set-Location '${tempDir}'; Compress-Archive -Path * -DestinationPath '${zipOut}' -Force"`);
fs.rmSync(tempDir, { recursive: true, force: true });

console.log('Created zip:', zipOut);
