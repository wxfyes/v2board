
import os

path = r'e:\GitHub\v2board\public\assets\admin\umi.js'
with open(path, 'r', encoding='utf-8') as f:
    content = f.read()

# Search for the specific render function body to be sure we are in the right place
marker = 'title: t.t ? "\\u6700\\u540e\\u5728\\u7ebf".concat(w()(1e3 * t.t).format("YYYY-MM-DD HH:mm:ss"))'
index = content.find(marker)

if index != -1:
    # Go back to the start of this object {
    start = content.rfind('{', 0, index)
    # Go even further back to find the previous object's end
    # Actually, let's find the end of this object }
    # Then insert our columns there.
    
    # We want to insert after the end of the email column object.
    # The email column object ends after its render function.
    
    # Let's find the next '}, {'
    insertion_point = content.find('}, {', index)
    if insertion_point != -1:
        # We'll insert after the '},'
        insertion_pos = insertion_point + 2
        
        # What to insert?
        new_cols = '{title:"客户端登录时间",dataIndex:"client_login_at",valueType:"dateTime",sorter:!0},{title:"客户端类型",dataIndex:"client_type",sorter:!0},'
        
        # Verify if it's already there (defensive)
        if 'client_login_at' in content:
            print("Already exists")
        else:
            new_content = content[:insertion_pos] + new_cols + content[insertion_pos:]
            with open(path, 'w', encoding='utf-8') as f:
                f.write(new_content)
            print(f"Successfully inserted at {insertion_pos}")
else:
    print("Pattern not found")
