import xml.etree.ElementTree as ET
import sys

tree = ET.parse('coverage/clover.xml')
target = sys.argv[1]
for f in tree.findall('.//file'):
    if f.attrib['name'].endswith(target):
        uncovered = [int(line.attrib['num']) for line in f.findall('line') if line.attrib['count'] == '0' and line.attrib['type'] == 'stmt']
        print(f'{f.attrib["name"]}: {len(uncovered)} uncovered lines: {uncovered}')
