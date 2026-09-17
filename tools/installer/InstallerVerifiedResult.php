<?php

declare(strict_types=1);

final class InstallerVerifiedResult
{
    public $stagingPath;
    public $installerRoot;
    public $version;
    public $manifest;
    public $candidateId;
    public $selectedMode;
    public $childName;

    public function __construct(string $stagingPath, string $installerRoot, string $version, array $manifest, string $candidateId, ?string $selectedMode = null, ?string $childName = null)
    {
        $this->stagingPath = $stagingPath;
        $this->installerRoot = $installerRoot;
        $this->version = $version;
        $this->manifest = $manifest;
        $this->candidateId = $candidateId;
        $this->selectedMode = $selectedMode;
        $this->childName = $childName;
        if (!is_dir($this->stagingPath) || is_link($this->stagingPath)
            || !is_dir($this->installerRoot) || is_link($this->installerRoot)
            || !preg_match('/\A[a-f0-9]{32}\z/', $this->candidateId)
            || ($this->manifest['version'] ?? null) !== $this->version
        ) {
            throw new InstallManifestException('placement_verify', 'Verified installer result is invalid.');
        }
        InstallManifest::validateManifest($this->manifest);
    }

    public static function fromArray(array $data): self
    {
        if (($data['verification_completed'] ?? false) !== true
            || !is_string($data['verified_staging_path'] ?? null)
            || !is_string($data['installer_root'] ?? null)
            || !is_string($data['version'] ?? null)
            || !is_array($data['manifest'] ?? null)
            || !is_string($data['transaction_candidate_id'] ?? null)
        ) {
            throw new InstallManifestException('placement_verify', 'Phase 2 verification result is not complete.');
        }
        return new self(
            $data['verified_staging_path'],
            $data['installer_root'],
            $data['version'],
            $data['manifest'],
            $data['transaction_candidate_id'],
            isset($data['selected_mode']) ? (string) $data['selected_mode'] : null,
            isset($data['child_directory']) ? (string) $data['child_directory'] : null
        );
    }

    public function toArray(): array
    {
        return [
            'verified_staging_path' => $this->stagingPath,
            'installer_root' => $this->installerRoot,
            'version' => $this->version,
            'manifest' => $this->manifest,
            'transaction_candidate_id' => $this->candidateId,
            'selected_mode' => $this->selectedMode,
            'child_directory' => $this->childName,
            'verification_completed' => true,
        ];
    }
}
