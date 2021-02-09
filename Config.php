<?php
class Config {
    const MON_HOST = 'fsm:27017/MailRobots';
    const MON_USER = 'mrobot';
    const MON_PWD =  '<pass>';

    const MSG_HOST = 'metal.bsw.iron';
    const MSG_PORT = 110;
    const MSG_USER = 'eorders';
    const MSG_PASS = '<pass>';
    
    // обрабатываем только сообщения от этого адреса
    const MSG_FROM = 'poisk_vagon@mnsk.rw.by';
    //const MSG_FROM = 'ag.novikov@bmz.gomel.by';
}
