import fs from 'fs';
import path from 'path';
import { execSync } from 'child_process';

const filesToInclude = [
    'routes/web.php',
    'routes/console.php',
    'config/services.php',
    'app/Http/Controllers/OnpeConsultaController.php',
    'app/Services/Onpe/Contracts/OnpeProviderInterface.php',
    'app/Services/Onpe/DTO/OnpeConsultaDTO.php',
    'app/Services/Onpe/Providers/CustomApiOnpeProvider.php',
    'app/Services/Onpe/Providers/MockOnpeProvider.php',
    'app/Services/Onpe/Providers/NativeHttpOnpeProvider.php',
    'app/Services/Onpe/Providers/OnpeDirectBridgeProvider.php',
    'app/Services/Onpe/Providers/ReniecApiOnpeProvider.php',
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
    if (fs.existsSync(src)) {
        const dest = path.join(tempDir, rel);
        fs.mkdirSync(path.dirname(dest), { recursive: true });
        fs.copyFileSync(src, dest);
    }
}

const zipOutCorregido = path.join(process.cwd(), 'actualizacion_cpanel_onpe_corregida.zip');
const zipOutLegacy = path.join(process.cwd(), 'actualizacion_cpanel_onpe.zip');

if (fs.existsSync(zipOutCorregido)) fs.unlinkSync(zipOutCorregido);
if (fs.existsSync(zipOutLegacy)) fs.unlinkSync(zipOutLegacy);

// Compress using powershell from inside tempDir
execSync(`powershell -Command "Set-Location '${tempDir}'; Compress-Archive -Path * -DestinationPath '${zipOutCorregido}' -Force"`);
fs.copyFileSync(zipOutCorregido, zipOutLegacy);
fs.rmSync(tempDir, { recursive: true, force: true });

console.log('Paquete corregido generado exitosamente:');
console.log('1. actualizacion_cpanel_onpe_corregida.zip');
console.log('2. actualizacion_cpanel_onpe.zip');

