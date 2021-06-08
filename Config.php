<?php
class Config {

    const MSG_HOST = 'metal.bsw.iron';
    const MSG_PORT = 110;
    const MSG_USER = 'eorders';
    const MSG_PASS = '<pass>';
    
    // обрабатываем только сообщения от этого адреса
    const MSG_FROM = 'poisk_vagon@mnsk.rw.by';
    const NUM_ATTEMPTS = 5;
    const RE_TIME = 600; //second
    //const MSG_FROM = 'ag.novikov@bmz.gomel.by';
}
