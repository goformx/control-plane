#!/usr/bin/env python3
"""Build both production targets and reject local-data markers in every layer."""

from __future__ import annotations

import json
import os
import subprocess
import tarfile
import tempfile
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
MARKER = b"GOFORMX_SYNTHETIC_CONTEXT_PROBE_20260929"
PROBES = (
    Path(".env.packaging-probe"),
    Path("config/.env.packaging-probe"),
    Path("storage/packaging-probe.sqlite"),
)
TARGETS = ("production", "web")


def run(*args: str) -> None:
    subprocess.run(args, cwd=ROOT, check=True)


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
    revision = os.environ.get("GITHUB_SHA", "local-test")
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
        for target in TARGETS:
            image = f"goformx-control-plane-{target}:context-probe"
            run(
                "docker", "buildx", "build", "--quiet", "--platform", "linux/amd64",
                "--build-arg", f"VCS_REF={revision}",
                "--target", target, "--load", "-t", image, ".",
            )
            inspect_layers(image)
    finally:
        for path in created:
            path.unlink(missing_ok=True)
        for path in reversed(created_dirs):
            path.rmdir()


if __name__ == "__main__":
    main()
