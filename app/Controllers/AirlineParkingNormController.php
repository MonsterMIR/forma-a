<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\View;
use App\Models\AirlineParkingNorm;

class AirlineParkingNormController extends Controller
{
    private AirlineParkingNorm $model;

    public function __construct(\App\Core\Request $req, \App\Core\Response $res)
    {
        parent::__construct($req, $res);
        $this->model = new AirlineParkingNorm();
    }

    public function index(array $params = []): void
    {
        $this->requireRole(['admin', 'commercial']);
        $this->render('airline_parking_norms/index', [
            'title' => 'Нормы бесплатной стоянки по авиакомпаниям',
            'items' => $this->model->getAllWithAirlines(),
        ]);
    }

    public function save(array $params = []): void
    {
        $this->requireRole(['admin', 'commercial']);

        $airlineId  = (int)$this->request->input('airline_id');
        $hoursPax   = (float)$this->request->input('free_hours_pax');
        $hoursCargo = (float)$this->request->input('free_hours_cargo');
        $notes      = trim((string)$this->request->input('notes', ''));

        if (!$airlineId || $hoursPax < 0 || $hoursCargo < 0) {
            View::setFlash('error', 'Проверьте введённые данные.');
            $this->redirect('/airline-parking-norms');
            return;
        }

        $this->model->upsert($airlineId, $hoursPax, $hoursCargo, $notes ?: null, Auth::id());
        View::setFlash('success', 'Норма стоянки сохранена.');
        $this->redirect('/airline-parking-norms');
    }
}
