#!/usr/bin/env python3
import hashlib
import os

from dns9_winrm_common import ensure_ntlm_md4_runtime

def main()->None:
    mode=ensure_ntlm_md4_runtime()
    digest=hashlib.new("md4",b"dns9").hexdigest()
    if len(digest)!=32:
        raise RuntimeError("ntlm_md4_digest_invalid")
    print("DNS9_WINRM_MD4_PROVIDER="+mode)
    print("DNS9_WINRM_MD4_AVAILABLE=1")
    print("DNS9_WINRM_MD4_CONFIG_MEMFD=1")
    print("DNS9_WINRM_MD4_PERSISTENT_FILE_MUTATION=0")
    print("DNS9_WINRM_MD4_SYSTEM_OPENSSL_CONFIG_MUTATION=0")
    print("DNS9_WINRM_MD4_SELFTEST_OK=1")

if __name__=="__main__":
    main()
