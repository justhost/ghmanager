#!/usr/bin/env python2
# coding: UTF-8

'''
***********************************************
Copyright (C) 2013 Nikita Bulaev

This library is free software; you can redistribute it and/or
modify it under the terms of the GNU Lesser General Public
License as published by the Free Software Foundation; either
version 2.1 of the License, or (at your option) any later version.

This library is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
Lesser General Public License for more details.
***********************************************
'''


print "Content-Type: text/html; charset=UTF-8"     # HTML is following
print                                              # blank line, end of headers

from optparse import OptionParser
from common import addInfoToConfig
import os
from shutil import move

parser = OptionParser()
parser.add_option("-s", "--server-path", action="store", type="string", dest="serverPath")
parser.add_option("-a", "--addon-path", action="store", type="string", dest="addonPath")
parser.add_option("-p", "--steam-id", action="store", type="string", dest="steamId")
parser.add_option("-g")
parser.add_option("-m")

(options, args) = parser.parse_args(args=None, values=None)
serverPath = options.serverPath
addonPath = options.addonPath
steamId = options.steamId

sourceModPath = serverPath + "/" + addonPath + "/addons/sourcemod"
serverCfg = serverPath + "/" + addonPath + "/cfg/warmod/on_map_load.cfg"

text = '''
// Запуск параметров для warmod extended
exec warmod/warmod_extended.cfg'''

addInfoToConfig(serverCfg, text)

try:
    # Переместить старый Warmod
    if os.path.exists(sourceModPath + '/plugins/warmod.smx'):
        move(sourceModPath + '/plugins/warmod.smx', sourceModPath + '/plugins/disabled/warmod.smx')

    # Включить mapchooser
    if os.path.exists(sourceModPath + '/plugins/disabled/mapchooser.smx'):
        move(sourceModPath + '/plugins/disabled/mapchooser.smx', sourceModPath + '/plugins/mapchooser.smx')

except OSError, e:
    print 'Возникла ошибка:', e
