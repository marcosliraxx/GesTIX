import importlib.util
import sys
import unittest
from pathlib import Path
from unittest.mock import patch
sys.path.insert(0, str(Path('gestores/windows').resolve()))
import arcoll_gestor as agent
class FakeSocket:
    def __init__(self, invalid=False): self.invalid=invalid
    def __enter__(self): return self
    def __exit__(self,*args): pass
    def settimeout(self,value): assert value<=0.5
    def sendto(self,data,target): self.query=data; self.target=target
    def recvfrom(self,size):
        data=self.query
        header=data[:2]+b'\x85\x00\x00\x01\x00\x01\x00\x00\x00\x00'
        payload=b'\x01'+b'SERVER-FISCAL  '+b'\x00\x00\x00'
        payload=b'\x01'+b'SERVER-FISCAL'.ljust(15,b' ')+b'\x00\x00\x00'
        reply=header+data[12:]+b'\xc0\x0c\x00\x21\x00\x01\x00\x00\x00\x00'+len(payload).to_bytes(2,'big')+payload
        return (b'xx'+reply[2:] if self.invalid else reply), (self.target[0],137)
class Names(unittest.TestCase):
    def test_actual_private_host_name(self):
        with patch.object(agent.socket,'socket',return_value=FakeSocket()):
            self.assertEqual(agent.netbios_query_name('192.168.18.244'),'SERVER-FISCAL')
    def test_transaction_mismatch_is_ignored(self):
        with patch.object(agent.socket,'socket',return_value=FakeSocket(True)):
            self.assertIsNone(agent.netbios_query_name('192.168.18.244'))
    def test_public_host_not_probed(self):
        with patch.object(agent.socket,'socket') as factory:
            self.assertIsNone(agent.netbios_query_name('8.8.8.8'))
            factory.assert_not_called()
if __name__=='__main__':unittest.main()
