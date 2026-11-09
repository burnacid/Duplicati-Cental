<#
.SYNOPSIS
    Builds and publishes a Duplicati Central release.

.DESCRIPTION
    1. Checks: clean working tree, up to date with origin, docker and gh available,
       changelog has entries under "Unreleased". When you are on another branch, it
       offers to merge that branch into the release branch (master) and release from there.
    2. Works out the version (VERSION file, -Bump or -Version).
    3. Runs the test suite (skip with -SkipTests).
    4. Updates VERSION and CHANGELOG.md, commits "Release vX.Y.Z" and creates tag vX.Y.Z.
    5. Builds the Docker image and pushes it to Docker Hub as X.Y.Z, X.Y and latest.
    6. Pushes the commit and tag, and creates a GitHub release with the changelog notes.

    If anything fails before step 6, the local release commit and tag are removed again.

.EXAMPLE
    ./release.ps1                 # first release: publishes the version in VERSION (0.1.0)
.EXAMPLE
    ./release.ps1 -Bump patch     # 0.1.0 -> 0.1.1
.EXAMPLE
    ./release.ps1 -Bump minor -DryRun   # show what would happen and build the image locally, publish nothing
.EXAMPLE
    ./release.ps1 -Bump minor -Platforms linux/amd64,linux/arm64   # multi-arch image (needs buildx)
#>
#Requires -Version 7
[CmdletBinding()]
param(
    [ValidateSet('major', 'minor', 'patch')]
    [string] $Bump,

    [ValidatePattern('^\d+\.\d+\.\d+$')]
    [string] $Version,

    [string] $Image = 'burnacid/duplicati-central',

    [string] $Platforms = 'linux/amd64',

    [string] $Branch = 'master',

    [switch] $SkipTests,

    [switch] $DryRun,

    # Do not ask for confirmation.
    [switch] $Yes
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$root = $PSScriptRoot
$repoUrl = 'https://github.com/burnacid/Duplicati-Cental'
$versionFile = Join-Path $root 'VERSION'
$changelogFile = Join-Path $root 'CHANGELOG.md'

function Write-Step([string] $Message) { Write-Host "`n==> $Message" -ForegroundColor Cyan }
function Write-Ok([string] $Message) { Write-Host "    $Message" -ForegroundColor Green }

# Run a native command and stop on a non-zero exit code.
function Invoke-Native {
    param([Parameter(Mandatory)][string] $File, [string[]] $Arguments = @(), [switch] $Quiet)
    if ($Quiet) {
        $output = & $File @Arguments 2>&1
    } else {
        & $File @Arguments
    }
    if ($LASTEXITCODE -ne 0) {
        if ($Quiet -and $output) { Write-Host ($output | Out-String) }
        throw "'$File $($Arguments -join ' ')' failed with exit code $LASTEXITCODE."
    }
    if ($Quiet) { return $output }
}

function Get-NativeOutput([string] $File, [string[]] $Arguments) {
    $output = & $File @Arguments 2>$null
    if ($LASTEXITCODE -ne 0) { throw "'$File $($Arguments -join ' ')' failed with exit code $LASTEXITCODE." }
    return ($output | Out-String).Trim()
}

function Step-Version([string] $Current, [string] $Part) {
    $p = $Current.Split('.') | ForEach-Object { [int] $_ }
    switch ($Part) {
        'major' { return "$($p[0] + 1).0.0" }
        'minor' { return "$($p[0]).$($p[1] + 1).0" }
        'patch' { return "$($p[0]).$($p[1]).$($p[2] + 1)" }
    }
}

# Returns the text of the "Unreleased" section (trimmed), or $null when the section is missing.
function Get-UnreleasedNotes([string] $Changelog) {
    $match = [regex]::Match($Changelog, '(?ms)^## \[Unreleased\][^\n]*\n(?<body>.*?)(?=^## \[|^\[Unreleased\]:|\z)')
    if (-not $match.Success) { return $null }
    return $match.Groups['body'].Value.Trim()
}

# Moves the "Unreleased" entries into a new version section and updates the comparison links.
function ConvertTo-ReleasedChangelog([string] $Changelog, [string] $NewVersion, [string] $PreviousTag, [string] $Date, [string] $RepoUrl) {
    $nl = if ($Changelog.Contains("`r`n")) { "`r`n" } else { "`n" }
    $tag = "v$NewVersion"
    $match = [regex]::Match($Changelog, '(?ms)^## \[Unreleased\][^\n]*\n(?<body>.*?)(?=^## \[|^\[Unreleased\]:|\z)')
    $notes = $match.Groups['body'].Value.Trim()

    $section = "## [Unreleased]$nl$nl## [$NewVersion] - $Date$nl$nl$notes$nl$nl"
    $text = $Changelog.Substring(0, $match.Index) + $section + $Changelog.Substring($match.Index + $match.Length).TrimStart("`r", "`n")

    $versionLink = if ($PreviousTag) { "[$NewVersion]: $RepoUrl/compare/$PreviousTag...$tag" } else { "[$NewVersion]: $RepoUrl/releases/tag/$tag" }
    if ($text -match '(?m)^\[Unreleased\]:') {
        $text = [regex]::Replace($text, '(?m)^\[Unreleased\]:[^\r\n]*', "[Unreleased]: $RepoUrl/compare/$tag...HEAD$nl$versionLink")
    } else {
        $text = $text.TrimEnd() + "$nl$nl[Unreleased]: $RepoUrl/compare/$tag...HEAD$nl$versionLink"
    }

    return $text.TrimEnd() + $nl
}

function Test-TagExists([string] $Tag) {
    & git rev-parse -q --verify "refs/tags/$Tag" *> $null
    if ($LASTEXITCODE -eq 0) { return $true }
    $remote = & git ls-remote --tags origin "refs/tags/$Tag" 2>$null
    return [bool] $remote
}

Push-Location $root
$releaseCommitted = $false
$published = $false
$merged = $false
$checkedOutRelease = $false
$featureBranch = $null
$branchBefore = $null
$tag = $null

try {
    # ---------------------------------------------------------------- checks
    Write-Step 'Checking prerequisites'

    foreach ($tool in @('git', 'docker') + $(if ($DryRun) { @() } else { @('gh') })) {
        if (-not (Get-Command $tool -ErrorAction SilentlyContinue)) { throw "'$tool' is not installed or not on PATH." }
    }
    Invoke-Native docker @('info') -Quiet | Out-Null
    if (-not $DryRun) {
        & gh auth status *> $null
        if ($LASTEXITCODE -ne 0) { throw "GitHub CLI is not logged in. Run 'gh auth login'." }
    }
    Write-Ok ('git, docker' + $(if ($DryRun) { '' } else { ' and gh' }) + ' available')

    $dirty = Get-NativeOutput git @('status', '--porcelain')
    if ($dirty) { throw "The working tree has uncommitted changes. Commit or stash them first:`n$dirty" }

    Invoke-Native git @('fetch', 'origin', '--tags') -Quiet | Out-Null
    $remoteBranchExists = [bool] (& git ls-remote --heads origin $Branch 2>$null)
    $currentBranch = Get-NativeOutput git @('rev-parse', '--abbrev-ref', 'HEAD')

    if ($currentBranch -ne $Branch) {
        if ($DryRun) {
            Write-Host "    Note: you are on '$currentBranch'. A real release would first merge it into '$Branch'. The dry run builds '$currentBranch'." -ForegroundColor Yellow
        } else {
            $ahead = [int] (Get-NativeOutput git @('rev-list', '--count', "$Branch..$currentBranch"))
            Write-Host "`nYou are on '$currentBranch' ($ahead commit(s) not in '$Branch'). Releases are made from '$Branch'." -ForegroundColor Yellow

            $merge = $Yes
            if (-not $merge) {
                $answer = Read-Host "Merge '$currentBranch' into '$Branch' and release from '$Branch'? [y/N]"
                $merge = $answer -match '^(y|yes)$'
            }
            if (-not $merge) { Write-Host 'Aborted. Nothing was changed.'; return }

            Write-Step "Merging '$currentBranch' into '$Branch'"
            $featureBranch = $currentBranch
            Invoke-Native git @('checkout', $Branch) -Quiet | Out-Null
            $checkedOutRelease = $true

            # Bring the local release branch up to date first (fast-forward only).
            if ($remoteBranchExists) {
                & git merge --ff-only "origin/$Branch" *> $null
                if ($LASTEXITCODE -ne 0) { throw "Local '$Branch' has diverged from origin/$Branch. Sort that out manually first." }
            }

            $branchBefore = Get-NativeOutput git @('rev-parse', 'HEAD')
            & git merge --no-ff $featureBranch -m "Merge branch '$featureBranch' into $Branch" *> $null
            if ($LASTEXITCODE -ne 0) {
                & git merge --abort *> $null
                throw "Merging '$featureBranch' into '$Branch' gives conflicts. Merge manually (or update '$featureBranch' from '$Branch') and run the release again."
            }
            $merged = $true
            $currentBranch = $Branch
            Write-Ok "Merged '$featureBranch' into '$Branch' (not pushed yet)"
        }
    }

    if ($currentBranch -eq $Branch -and $remoteBranchExists) {
        $behind = [int] (Get-NativeOutput git @('rev-list', '--count', "HEAD..origin/$Branch"))
        if ($behind -gt 0) { throw "'$Branch' is $behind commit(s) behind origin/$Branch. Pull first." }
    }
    Write-Ok "On $currentBranch, clean and up to date"

    # --------------------------------------------------------------- version
    Write-Step 'Determining version'

    $current = (Get-Content $versionFile -Raw).Trim()
    if ($current -notmatch '^\d+\.\d+\.\d+$') { throw "VERSION contains '$current', expected X.Y.Z." }

    if ($Version) {
        $new = $Version
    } elseif ($Bump) {
        $new = Step-Version $current $Bump
    } elseif (-not (Test-TagExists "v$current")) {
        $new = $current   # first release of the version in VERSION
    } else {
        throw "v$current is already released. Use -Bump patch|minor|major or -Version X.Y.Z."
    }

    $tag = "v$new"
    if (Test-TagExists $tag) { throw "Tag $tag already exists." }

    $previousTag = (& git tag --list 'v*' --sort=-v:refname | Select-Object -First 1)
    $parts = $new.Split('.')
    $tags = @("${Image}:$new", "${Image}:$($parts[0]).$($parts[1])", "${Image}:latest")
    Write-Ok "Version $current -> $new (tag $tag)"

    # ------------------------------------------------------------- changelog
    Write-Step 'Reading changelog'

    $changelog = Get-Content $changelogFile -Raw
    $nl = if ($changelog.Contains("`r`n")) { "`r`n" } else { "`n" }
    $notes = Get-UnreleasedNotes $changelog
    if ($null -eq $notes) { throw "CHANGELOG.md has no '## [Unreleased]' section." }
    if ($notes -notmatch '(?m)^\s*[-*] ') { throw "The 'Unreleased' section in CHANGELOG.md has no entries. Describe the changes first." }
    Write-Host $notes

    if (-not $Yes -and -not $DryRun) {
        Write-Host "`nThis will publish $tag to GitHub and push $($tags -join ', ') to Docker Hub." -ForegroundColor Yellow
        $answer = Read-Host 'Continue? [y/N]'
        if ($answer -notmatch '^(y|yes)$') { Write-Host 'Aborted.'; return }
    }

    # ----------------------------------------------------------------- tests
    if ($SkipTests) {
        Write-Step 'Skipping tests (-SkipTests)'
    } else {
        Write-Step 'Running tests'
        Push-Location (Join-Path $root 'app')
        try {
            # A cached config makes Laravel ignore phpunit.xml (tests would use the real database).
            Invoke-Native php @('artisan', 'config:clear') -Quiet | Out-Null
            Invoke-Native php @('artisan', 'test', '--compact')
        } finally {
            Pop-Location
        }
    }

    # ------------------------------------------------- version + changelog commit
    if (-not $DryRun) {
        Write-Step "Preparing release commit $tag"

        $updated = ConvertTo-ReleasedChangelog $changelog $new $previousTag (Get-Date -Format 'yyyy-MM-dd') $repoUrl
        Set-Content -Path $changelogFile -Value $updated -NoNewline
        Set-Content -Path $versionFile -Value $new

        Invoke-Native git @('add', 'VERSION', 'CHANGELOG.md')
        Invoke-Native git @('commit', '-m', "Release $tag") -Quiet | Out-Null
        Invoke-Native git @('tag', '-a', $tag, '-m', "Duplicati Central $tag")
        $releaseCommitted = $true
        Write-Ok "Committed and tagged $tag locally"
    }

    # ----------------------------------------------------------- docker image
    Write-Step "Building Docker image ($Platforms)"

    $revision = Get-NativeOutput git @('rev-parse', '--short', 'HEAD')
    $buildArgs = @(
        'buildx', 'build', $root,
        '--platform', $Platforms,
        '--build-arg', "APP_VERSION=$new",
        '--build-arg', "VCS_REF=$revision",
        '--build-arg', "BUILD_DATE=$((Get-Date).ToUniversalTime().ToString('yyyy-MM-ddTHH:mm:ssZ'))"
    )
    foreach ($t in $tags) { $buildArgs += @('--tag', $t) }

    $multiArch = $Platforms.Contains(',')
    if ($multiArch -and -not $DryRun) {
        # Multi-platform images cannot be loaded locally; build and push in one go.
        Invoke-Native docker ($buildArgs + '--push')
        Write-Ok "Built and pushed $($tags -join ', ')"
    } elseif ($multiArch) {
        Invoke-Native docker $buildArgs
        Write-Ok 'Built (dry run, not pushed)'
    } else {
        Invoke-Native docker ($buildArgs + '--load')
        Write-Ok "Built $($tags -join ', ')"

        if (-not $DryRun) {
            Write-Step 'Pushing image to Docker Hub'
            foreach ($t in $tags) { Invoke-Native docker @('push', $t) }
            Write-Ok 'Pushed'
        }
    }

    if ($DryRun) {
        Write-Step 'Dry run finished'
        Write-Host "Would release $tag and push $($tags -join ', '). Nothing was committed, tagged or published."
        return
    }

    # ------------------------------------------------------- git + github release
    Write-Step 'Publishing to GitHub'

    Invoke-Native git @('push', 'origin', $Branch)
    Invoke-Native git @('push', 'origin', $tag)
    $published = $true

    $notesFile = New-TemporaryFile
    try {
        $releaseNotes = "$notes$nl$nl## Docker$nl$nl``````$nl" + "docker pull ${Image}:$new$nl``````"
        Set-Content -Path $notesFile -Value $releaseNotes
        Invoke-Native gh @('release', 'create', $tag, '--title', "Duplicati Central $tag", '--notes-file', $notesFile.FullName, '--verify-tag')
    } finally {
        Remove-Item $notesFile -ErrorAction SilentlyContinue
    }

    Write-Step "Released $tag"
    Write-Ok "Docker:  docker pull ${Image}:$new"
    Write-Ok "GitHub:  $repoUrl/releases/tag/$tag"
    if ($merged) {
        Write-Ok "'$featureBranch' was merged into '$Branch'; you are now on '$Branch'."
    }
}
catch {
    Write-Host "`nRelease failed: $($_.Exception.Message)" -ForegroundColor Red

    if (-not $published) {
        if ($releaseCommitted) {
            Write-Host 'Removing the local release commit and tag...' -ForegroundColor Yellow
            & git tag -d $tag *> $null
            & git reset --hard HEAD~1 *> $null
            Write-Host 'Note: image tags that were already pushed to Docker Hub stay there; the next run overwrites them.' -ForegroundColor Yellow
        }
        if ($merged) {
            Write-Host "Undoing the merge into '$Branch'..." -ForegroundColor Yellow
            & git reset --hard $branchBefore *> $null
        }
        if ($checkedOutRelease -and $featureBranch) {
            & git checkout $featureBranch *> $null
            Write-Host "Switched back to '$featureBranch'." -ForegroundColor Yellow
        }
        if ($releaseCommitted -or $merged) {
            Write-Host 'Local repository restored. Nothing was pushed to GitHub.' -ForegroundColor Yellow
        }
    } else {
        Write-Host "The commit and tag were pushed. Create the GitHub release manually: gh release create $tag --notes-file <notes>" -ForegroundColor Yellow
    }

    exit 1
}
finally {
    Pop-Location
}
