#!/usr/bin/env python3
"""Build both production targets and reject local-data markers in every layer."""

from __future__ import annotations

import json
import subprocess
import tarfile
import tempfile
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
MARKER = b"GOFORMX_SYNTHETIC_CONTEXT_PROBE_20260929"
PROBES = (
    Path(".env.packaging-probe"),
    Path("config/.env.packaging-probe"),
    Path("config/packaging-probe-secret.php"),
    Path("public/assets/packaging-probe-private.json"),
    Path("storage/packaging-probe.sqlite"),
)
TARGETS = ("production", "web")


def git(*args: str) -> tuple[str, ...]:
    """Resolve this Windows-created worktree's gitdir when running in WSL."""
    dotgit = ROOT / ".git"
    if dotgit.is_file():
        gitdir = dotgit.read_text().strip().removeprefix("gitdir: ")
        if len(gitdir) > 2 and gitdir[1:3] == ":/":
            gitdir = f"/mnt/{gitdir[0].lower()}/{gitdir[3:]}"
        return ("git", f"--git-dir={gitdir}", *args)
    return ("git", *args)


def inspect_layers(image: str) -> None:
    with tempfile.TemporaryFile() as archive:
        subprocess.run(["docker", "save", image], stdout=archive, check=True)
        archive.seek(0)
        with tarfile.open(fileobj=archive) as image_tar:
            manifest_file = image_tar.extractfile("manifest.json")
            assert manifest_file is not None
            manifest = json.load(manifest_file)
            layers = manifest[0]["Layers"]
            if not layers:
                raise RuntimeError(f"No layers found for {image}")
            for layer in layers:
                layer_file = image_tar.extractfile(layer)
                assert layer_file is not None
                with tarfile.open(fileobj=layer_file, mode="r|*") as layer_tar:
                    for member in layer_tar:
                        if not member.isfile():
                            continue
                        if any(member.name.endswith(str(probe)) for probe in PROBES):
                            raise RuntimeError(f"Probe filename found in {image} layer")
                        content = layer_tar.extractfile(member)
                        assert content is not None
                        overlap = b""
                        while chunk := content.read(1024 * 1024):
                            if MARKER in overlap + chunk:
                                raise RuntimeError(f"Probe content found in {image} layer")
                            overlap = chunk[-(len(MARKER) - 1) :]
    print(f"{image}: {len(layers)} layers clear")


def main() -> None:
    created: list[Path] = []
    created_dirs: list[Path] = []
    revision = subprocess.check_output(git("rev-parse", "HEAD"), cwd=ROOT, text=True).strip()
    try:
        for relative in PROBES:
            path = ROOT / relative
            if path.exists():
                raise RuntimeError(f"Probe path already exists: {relative}")
            if not path.parent.exists():
                path.parent.mkdir(parents=True)
                created_dirs.append(path.parent)
            path.write_bytes(MARKER)
            created.append(path)
        # Release context comes only from tracked bytes at this exact commit.
        # Local files inside COPY directories cannot enter any image layer.
        with tempfile.TemporaryFile() as source:
            subprocess.run(git("archive", "--format=tar", "HEAD"), cwd=ROOT, stdout=source, check=True)
            for target in TARGETS:
                image = f"goformx-control-plane-{target}:context-probe"
                source.seek(0)
                subprocess.run(
                    ["docker", "buildx", "build", "--quiet", "--platform", "linux/amd64",
                     "--build-arg", f"VCS_REF={revision}", "--target", target,
                     "--load", "-t", image, "-"],
                    cwd=ROOT, stdin=source, check=True,
                )
                inspect_layers(image)
    finally:
        for path in created:
            path.unlink(missing_ok=True)
        for path in reversed(created_dirs):
            path.rmdir()


if __name__ == "__main__":
    main()
