(function($) {
    var app = angular.module('app', ['infinite-scroll']); 
    //directive that cheks if an image is loaded
    /*app.directive('imageonload', function() {
        return {
            restrict: 'A',
            link: function(scope, element, attrs) {
                element.bind('load', function() {
                    return true;
                });
                element.bind('error', function(){
                    return false;
                });
            }
        };
    });*/

    app.controller('pictureController', ['$scope', 'pictureAndPostFactory', function($scope, pictureAndPostFactory) {
        //URL that gets all socialmedia posts that are (primarily) pictures
        $scope.data =[];
        $scope.masterArray =[];
        $scope.orginalArray =[]; // The purpose of this array is to store the original layout of the masterArray
        
        //this functions loads more data for the infinite scroll
        // it constantily updates the array from which ng-rpeat gets its data
        $scope.loadMore = function(x) {
            //Function that gets the data 
            //it transforms the object into an array and runs 
            pictureAndPostFactory.imageList(x).then(function(successResponse){
                $scope.masterArray = Object.values(successResponse)
                console.log($scope.masterArray);
                $scope.originalArray = $scope.masterArray.slice();
                loadData();
            });   
        };

        var loadData = function(){
            var last = [];
                    var x
                    if ( $scope.data.length == 0){
                        x = 0
                    }
                    else {
                        x = $scope.data.length - 1;
                    }
                    for(var i = 1; i <= 20; i++) {
                        if ($scope.masterArray.length-1 < $scope.data.length)
                        {      
                            return
                        } else {
                            var currentValue = $scope.masterArray[x+i]; 
                            console.log($scope.data.length);
                            console.log($scope.masterArray.length);       
                            $scope.data.push(currentValue);
                        }
                    };
            };



        //using a factory gets a list of all pirate parties using $http.get and it transforms the object into array of objects

        pictureAndPostFactory.partyList().then(function(successResponse){
            $scope.partyList = Object.values(successResponse)
            console.log($scope.partyList);
        });
        

        //ensure that you can click anywhere inside the li to check the dropdown radio button
        $(".party-dropdown").click(function(){
            $("this > input").attr("checked", "checked")
        })

        $scope.emptyInput = function(){
            $scope.partySelection = "";
        }

        //function that sorts entries by reach in ascending order
        $scope.sortAscViews = function(){ 
            $scope.masterArray.sort(function(a, b){
            return a.post_likes > b.post_likes;
        });
        $scope.data = [];
        loadData();
        };
        //function that sorts entries by reach in descending order
        $scope.sortDescViews = function(){
        $scope.data = [];
        $scope.loadMore("&order_by=likes");
        };

        $scope.defaultSort = function(){
        $scope.masterArray = $scope.originalArray.slice(); 
        $scope.data = [];
        $scope.loadMore(); 
        $(".up").removeClass("arrow-color")
        $(".down").removeClass("arrow-color")
        }

        //function that toggles between ascending and descending amount of reach
        $scope.sortByViews = function(){
            if ( $("#asc-desc-views").hasClass("desc") ) {
                $("#asc-desc-views").removeClass("desc")
                $("#asc-desc-views").addClass("asc")
                $scope.sortAscViews()
            }
            else {
                $("#asc-desc-views").addClass("toggled")
                $("#asc-desc-views").addClass("desc")
                $("#asc-desc-views").removeClass("asc")
                $scope.sortDescViews();
            };

        } 
        //stops dropdown from closing
        $('#platform-selection').bind('click', function (e) { e.stopPropagation() })
        $('#party-selection').bind('click', function (e) { e.stopPropagation() })

    }]);
})(jQuery); // end of jQuery name space