<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Static_packages extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->require_role(array('superadmin', 'admin'));
        $this->load->helper(array('url'));
    }

    public function index()
    {
        return redirect('ppp-profiles');
    }

    public function edit($id = null)
    {
        return redirect('ppp-profiles/edit/' . (int) $id);
    }

    public function update($id = null)
    {
        return redirect('ppp-profiles/update/' . (int) $id);
    }
}
