import sys
import json
import requests
import re

def get_meta(url):
    # Convert to embed URL
    url = url.split('?')[0]
    embed_url = url.replace('/playlist/', '/embed/playlist/').replace('/track/', '/embed/track/').replace('/album/', '/embed/album/')
    
    headers = {
        'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/110.0.0.0 Safari/537.36'
    }
    
    try:
        r = requests.get(embed_url, headers=headers, timeout=10)
        r.raise_for_status()
        html = r.text
        
        # Search for JSON resource
        match = re.search(r'<script id="resource" type="application/json">(.+?)</script>', html, re.S)
        if match:
            data = json.loads(match.group(1))
            return {"success": True, "data": data}
        
        return {"success": False, "error": "No JSON found in HTML"}
    except Exception as e:
        return {"success": False, "error": str(e)}

if __name__ == "__main__":
    if len(sys.argv) < 2:
        print(json.dumps({"success": False, "error": "No URL provided"}))
    else:
        print(json.dumps(get_meta(sys.argv[1])))
