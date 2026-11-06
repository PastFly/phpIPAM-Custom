#!/usr/bin/env python3
"""Exercise the bundle builder against synthetic Git inputs, never its runner."""
import os
from pathlib import Path
import re
import shlex
import shutil
import subprocess
import tarfile
import tempfile

BUILDER = Path(__file__).resolve().parents[2] / 'tools/live/dns9-build-test-bundle.sh'


def run(args, cwd, env):
    return subprocess.run(args, cwd=cwd, env=env, stdout=subprocess.PIPE, stderr=subprocess.PIPE)


def main():
    source = BUILDER.read_text()
    match = re.search(r'for file in \\\n(.*?)\; do\n', source, re.S)
    assert match, 'payload allowlist not found'
    files = re.findall(r'^\s+([^\s\\]+)', match.group(1), re.M)
    assert len(files) > 1 and len(files) == len(set(files))
    with tempfile.TemporaryDirectory(prefix='dns9-bundle-fixture-') as tmp:
        root = Path(tmp)
        repo, output, bin_dir, template = (root / p for p in ('repo', 'output', 'bin', 'template'))
        for directory in (repo, output, bin_dir, template):
            directory.mkdir()
        # Prefer the real GNU tar, including its reproducibility options. BSD tar
        # needs an adapter for those five options; it still creates a real archive.
        tar_mode = None
        for name in ('gtar', 'tar'):
            candidate = shutil.which(name)
            if not candidate:
                continue
            version = subprocess.run([candidate, '--version'], stdout=subprocess.PIPE,
                                     stderr=subprocess.PIPE, text=True)
            if version.returncode == 0 and 'GNU tar' in version.stdout:
                (bin_dir / 'tar').symlink_to(candidate)
                tar_mode = 'GNU'
                break
        if tar_mode is None:
            candidate = shutil.which('tar')
            assert candidate, 'tar is required'
            version = subprocess.run([candidate, '--version'], stdout=subprocess.PIPE,
                                     stderr=subprocess.PIPE, text=True)
            assert version.returncode == 0 and 'bsdtar' in version.stdout, 'unsupported tar'
            tar = bin_dir / 'tar'
            tar.write_text('#!/bin/bash\nset -eu\nshift 5\nexec ' + shlex.quote(candidate) + ' "$@"\n')
            tar.chmod(0o755)
            tar_mode = 'BSD_ADAPTER'
        env = os.environ.copy()
        env.update(PATH=str(bin_dir) + os.pathsep + env['PATH'], GIT_CONFIG_NOSYSTEM='1',
                   GIT_CONFIG_GLOBAL='/dev/null', GIT_AUTHOR_NAME='Synthetic',
                   GIT_AUTHOR_EMAIL='synthetic@example.invalid', GIT_COMMITTER_NAME='Synthetic',
                   GIT_COMMITTER_EMAIL='synthetic@example.invalid', TMPDIR=str(root))
        def git(*args):
            result = run(['git', '-c', 'core.hooksPath=/dev/null', *args], repo, env)
            assert result.returncode == 0, 'fixture git command failed'
            return result.stdout.decode().strip()
        git('init', '-q', '--template=' + str(template))
        for file in files:
            dest = repo / file
            dest.parent.mkdir(parents=True, exist_ok=True)
            dest.write_text('synthetic committed payload\n')
        builder = repo / 'tools/live/dns9-build-test-bundle.sh'
        builder.write_text(source)
        (repo / files[1]).chmod(0o755)
        git('add', '--', '.')
        git('commit', '-q', '-m', 'synthetic fixture')
        head = git('rev-parse', 'HEAD')
        target = repo / files[0]

        build_count = 0
        def build():
            nonlocal build_count
            build_count += 1
            destination = output / str(build_count)
            result = run(['bash', str(builder), str(destination)], repo, env)
            return result, destination
        def check_archive(destination):
            package = destination / ('phpipam-dns9-test-' + head[:12] + '.tar.gz')
            with tarfile.open(package, 'r:gz') as archive:
                assert archive.extractfile('./EXPECTED_SHA').read() == (head + '\n').encode()
                assert archive.extractfile('./' + files[0]).read() == b'synthetic committed payload\n', 'uncommitted bytes entered archive'
                assert archive.getmember('./' + files[1]).mode & 0o111, 'committed executable mode must survive'

        result, destination = build()
        assert result.returncode == 0, 'clean builder must succeed'
        check_archive(destination)
        assert (destination / ('phpipam-dns9-test-' + head[:12] + '.run')).exists()

        marker = '# synthetic-wrapper-marker@example.invalid'
        altered = source.replace("cat > \"$RUN\" <<'HEADER'\n", "cat > \"$RUN\" <<'HEADER'\n" + marker + '\n')
        assert altered != source, 'wrapper fixture marker not inserted'
        builder.write_text(altered)
        git('add', '--', str(builder.relative_to(repo)))
        result, destination = build()
        if result.returncode == 0:
            wrapper = destination / ('phpipam-dns9-test-' + head[:12] + '.run')
            assert marker.encode() in wrapper.read_bytes(), 'fixture did not alter wrapper'
            raise AssertionError('DIRTY_BUILDER_ACCEPTED_WRAPPER_MARKER')
        assert result.returncode != 0, 'staged builder must be rejected'
        assert not destination.exists(), 'staged builder must fail before output creation'
        git('restore', '--staged', '--worktree', '--', str(builder.relative_to(repo)))
        builder.write_text(altered)
        result, destination = build()
        assert result.returncode != 0, 'unstaged builder must be rejected'
        assert not destination.exists(), 'unstaged builder must fail before output creation'
        builder.write_text(source)

        target.write_text('synthetic staged payload\n')
        git('add', '--', files[0])
        result, destination = build()
        assert result.returncode == 0, 'builder failed on staged input'
        check_archive(destination)
        target.write_text('synthetic unstaged payload\n')
        result, destination = build()
        assert result.returncode == 0, 'builder failed on dirty input'
        check_archive(destination)
        target.unlink()
        result, destination = build()
        assert result.returncode == 0, 'builder failed on missing worktree input'
        check_archive(destination)
        target.symlink_to(root / 'synthetic-outside')
        (root / 'synthetic-outside').write_text('synthetic symlink payload\n')
        result, destination = build()
        assert result.returncode == 0, 'builder failed on symlink worktree input'
        check_archive(destination)
        target.unlink()
        git('rm', '--cached', '--', files[0])
        git('commit', '-q', '-m', 'synthetic missing committed input')
        missing_head = git('rev-parse', 'HEAD')
        target.write_text('synthetic untracked payload\n')
        result, destination = build()
        assert result.returncode != 0, 'missing committed payload must fail closed'
        assert not (destination / ('phpipam-dns9-test-' + missing_head[:12] + '.tar.gz')).exists()
        assert not (destination / ('phpipam-dns9-test-' + missing_head[:12] + '.run')).exists()
    print('DNS9_IMMUTABLE_BUNDLE_CASES=8 BEHAVIOR_ASSERTIONS=28 TAR=' + tar_mode)


if __name__ == '__main__':
    main()
