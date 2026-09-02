route[NEW_CALL] {
    custom_routing_policy();
    ds_select_dst(1, 4);
    rtpengine_offer("fixture flags must remain redacted");
    t_relay();
}

route[EXISTING_CALL] {
    loose_route();
    rtpengine_manage("fixture flags must remain redacted");
    t_relay();
}

route[TERMINATE_CALL] {
    rtpengine_delete("fixture flags must remain redacted");
    t_relay();
}

route[CANCEL_CALL] {
    rtpengine_delete("fixture flags must remain redacted");
    t_relay();
}

onreply_route[MEDIA_REPLY] {
    rtpengine_answer("fixture flags must remain redacted");
}
