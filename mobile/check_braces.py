import sys

file_path = 'backend/index.php'
with open(file_path, 'r', encoding='utf-8') as f:
    content = f.read()

stack = []
for i, char in enumerate(content):
    if char == '{':
        stack.append(('{', i))
    elif char == '}':
        if not stack:
            print(f"Unbalanced '}}' found at index {i}")
            # Get surrounding text
            start = max(0, i - 40)
            end = min(len(content), i + 40)
            print(f"Context: {content[start:end]}")
        else:
            stack.pop()

if stack:
    print(f"Unbalanced '{{' remaining: {len(stack)}")
    for s, idx in stack[:5]:
        start = max(0, idx - 40)
        end = min(len(content), idx + 40)
        print(f"Unbalanced '{{' at index {idx}: {content[start:end]}")
else:
    print("Braces are balanced (basic check).")
