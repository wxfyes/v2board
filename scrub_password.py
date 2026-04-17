import os
import sys

files_to_fix = ['config/bobutil.php', '.agent/PROJECT_STATUS.md']
passwords = ['nluu zrwy ivvx gtlx', 'nluuzrwyivvxgtlx']

for file_path in files_to_fix:
    if os.path.exists(file_path):
        with open(file_path, 'r', encoding='utf-8', errors='ignore') as f:
            content = f.read()
        
        new_content = content
        for pwd in passwords:
            new_content = new_content.replace(pwd, 'REDACTED_PASSWORD')
        
        if new_content != content:
            with open(file_path, 'w', encoding='utf-8') as f:
                f.write(new_content)
