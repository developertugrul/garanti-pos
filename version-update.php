<?php

/**
 * Garanti Pos - Versiyon Güncelleme Script'i
 *
 * Kullanım:
 * php version-update.php [major|minor|patch]
 *
 * Örnekler:
 * php version-update.php patch   # 1.0.0 -> 1.0.1
 * php version-update.php minor   # 1.0.0 -> 1.1.0
 * php version-update.php major   # 1.0.0 -> 2.0.0
 */

class VersionUpdater
{
    private $composerFile = 'composer.json';
    private $currentVersion;

    public function __construct()
    {
        $this->loadCurrentVersion();
    }

    private function loadCurrentVersion()
    {
        if (!file_exists($this->composerFile)) {
            throw new Exception("composer.json dosyası bulunamadı!");
        }

        $content = file_get_contents($this->composerFile);
        $data = json_decode($content, true);

        if (!isset($data['version'])) {
            // Versiyon yoksa 1.0.0 olarak başlat
            $this->currentVersion = '1.0.0';
            $this->updateComposerVersion($this->currentVersion);
        } else {
            $this->currentVersion = $data['version'];
        }
    }

    private function updateComposerVersion($newVersion)
    {
        $content = file_get_contents($this->composerFile);
        $data = json_decode($content, true);
        $data['version'] = $newVersion;

        file_put_contents($this->composerFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function updateVersion($type)
    {
        $parts = explode('.', $this->currentVersion);

        switch ($type) {
            case 'major':
                $parts[0]++;
                $parts[1] = 0;
                $parts[2] = 0;
                break;
            case 'minor':
                $parts[1]++;
                $parts[2] = 0;
                break;
            case 'patch':
                $parts[2]++;
                break;
            default:
                throw new Exception("Geçersiz versiyon tipi: $type");
        }

        $newVersion = implode('.', $parts);

        // Composer.json'u güncelle
        $this->updateComposerVersion($newVersion);

        // Git tag oluştur
        $this->createGitTag($newVersion);

        echo "✅ Versiyon güncellendi: {$this->currentVersion} -> {$newVersion}\n";
        echo "✅ Git tag oluşturuldu: v{$newVersion}\n";
        echo "✅ Composer.json güncellendi\n";

        return $newVersion;
    }

    private function createGitTag($version)
    {
        // Git tag oluştur
        exec("git add composer.json");
        exec("git commit -m \"Bump version to {$version}\"");
        exec("git tag -a v{$version} -m \"Version {$version}\"");

        // Tag'i push et
        exec("git push origin v{$version}");
        exec("git push origin main");

        echo "📝 Git commit ve tag oluşturuldu\n";
        echo "🚀 Tag GitHub'a push edildi: v{$version}\n";
    }

    public function showCurrentVersion()
    {
        echo "📦 Mevcut versiyon: {$this->currentVersion}\n";
    }
}

// Script çalıştırma
if (php_sapi_name() === 'cli') {
    $updater = new VersionUpdater();

    if ($argc < 2) {
        echo "📋 Kullanım: php version-update.php [major|minor|patch]\n\n";
        echo "🔧 Versiyon tipleri:\n";
        echo "   patch: 1.0.0 -> 1.0.1 (hata düzeltmeleri)\n";
        echo "   minor: 1.0.0 -> 1.1.0 (yeni özellikler)\n";
        echo "   major: 1.0.0 -> 2.0.0 (büyük değişiklikler)\n\n";
        $updater->showCurrentVersion();
        exit(1);
    }

    $type = $argv[1];

    try {
        $updater->updateVersion($type);
    } catch (Exception $e) {
        echo "❌ Hata: " . $e->getMessage() . "\n";
        exit(1);
    }
}
