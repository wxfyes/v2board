
import os

path = r'e:\GitHub\v2board\public\assets\admin\umi.js'
with open(path, 'r', encoding='utf-8') as f:
    content = f.read()

# Look for the email column definition
key = 'key: "email"'
index = content.find(key)

if index != -1:
    # Find the end of the object
    # It usually ends with }, {
    end_of_email = content.find('}, {', index)
    if end_of_email != -1:
        # We can insert after the first }, 
        print(f"Index: {end_of_email}")
        print(content[end_of_email-100:end_of_email+300])
else:
    print("Not found")
