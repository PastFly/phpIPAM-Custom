#!/usr/bin/env python3
"""Exercise the bundle builder against synthetic Git inputs, never its runner."""
import os
from pathlib import Path
import re
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
        # BSD tar cannot accept the builder's GNU reproducibility flags. Preserve
        # actual archive creation while adapting only those five flags in this fixture.
        tar = bin_dir / 'tar'
        tar.write_text('#!/bin/bash\nset -eu\nshift 5\nexec /usr/bin/tar "$@"\n')
        tar.chmod(0o755)
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
        (repo / files[1]).chmod(0o755)
        git('add', '--', '.')
        git('commit', '-q', '-m', 'synthetic fixture')
        head = git('rev-parse', 'HEAD')
        target = repo / files[0]

        def build():
            return run(['bash', str(BUILDER), str(output)], repo, env)
        def check_archive():
            package = output / ('phpipam-dns9-test-' + head[:12] + '.tar.gz')
            with tarfile.open(package, 'r:gz') as archive:
                assert archive.extractfile('./EXPECTED_SHA').read() == (head + '\n').encode()
                assert archive.extractfile('./' + files[0]).read() == b'synthetic committed payload\n', 'uncommitted bytes entered archive'
                assert archive.getmember('./' + files[1]).mode & 0o111, 'committed executable mode must survive'

        target.write_text('synthetic staged payload\n')
        git('add', '--', files[0])
        assert build().returncode == 0, 'builder failed on staged input'
        check_archive()
        target.write_text('synthetic unstaged payload\n')
        assert build().returncode == 0, 'builder failed on dirty input'
        check_archive()
        target.unlink()
        assert build().returncode == 0, 'builder failed on missing worktree input'
        check_archive()
        target.symlink_to(root / 'synthetic-outside')
        (root / 'synthetic-outside').write_text('synthetic symlink payload\n')
        assert build().returncode == 0, 'builder failed on symlink worktree input'
        check_archive()
        target.unlink()
        git('rm', '--cached', '--', files[0])
        git('commit', '-q', '-m', 'synthetic missing committed input')
        missing_head = git('rev-parse', 'HEAD')
        target.write_text('synthetic untracked payload\n')
        assert build().returncode != 0, 'missing committed payload must fail closed'
        assert not (output / ('phpipam-dns9-test-' + missing_head[:12] + '.tar.gz')).exists()
        assert not (output / ('phpipam-dns9-test-' + missing_head[:12] + '.run')).exists()
    print('DNS9_IMMUTABLE_BUNDLE_CASES=5 BEHAVIOR_ASSERTIONS=19')


if __name__ == '__main__':
    main()
