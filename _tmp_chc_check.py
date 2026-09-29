import urllib.request, re
html = urllib.request.urlopen('https://chc.fin-proms.com/?t=1', timeout=20).read().decode('utf-8','replace')
print(html)
assets = re.findall(r'src="([^"]+\.js)"', html)
print('JS:', assets)
for a in assets:
    url = a if a.startswith('http') else 'https://chc.fin-proms.com' + a
    js = urllib.request.urlopen(url, timeout=60).read().decode('utf-8','replace')
    print('member_view_site_pages', 'member_view_site_pages' in js)
    print('canViewSitePages', 'canViewSitePages' in js)
    # minify may rename - look for my-dashboard navigate patterns near central
    print('is_control_plane string', 'is_control_plane' in js or 'isControlPlane' in js)
    print('Back to website', 'Back to website' in js)
